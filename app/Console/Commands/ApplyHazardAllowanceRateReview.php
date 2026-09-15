<?php

namespace App\Console\Commands;

use App\Domain\Staff\Models\AllowanceType;
use App\Domain\Staff\Models\SalaryStructureRate;
use App\Domain\Staff\Models\SalaryStructureRateAllowance;
use App\Domain\Staff\Models\Staff;
use App\Domain\Staff\Services\AllowanceTypeProvisioningService;
use App\Domain\Staff\Services\StaffRecomputeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ApplyHazardAllowanceRateReview extends Command
{
    protected $signature = 'salary-structure:apply-hazard-review
        {--dry-run : Report changes without saving them}
        {--recompute-current-staff : Recompute current salary snapshots for staff on affected scales after saving rates}';

    protected $description = 'Apply the reviewed hazard allowance rates for GL, CH, and CM salary structures.';

    public function handle(
        AllowanceTypeProvisioningService $allowanceTypeProvisioningService,
        StaffRecomputeService $staffRecomputeService,
    ): int {
        $dryRun = (bool) $this->option('dry-run');
        $recomputeCurrentStaff = (bool) $this->option('recompute-current-staff');
        $hazardType = $this->hazardAllowanceType($allowanceTypeProvisioningService);

        if (! $hazardType) {
            $this->components->error('Unable to create or find the hazard allowance type.');

            return self::FAILURE;
        }

        $summary = [
            'rates_matched' => 0,
            'allowance_rows_created' => 0,
            'allowance_rows_updated' => 0,
            'allowance_rows_unchanged' => 0,
            'unsupported_rates_skipped' => 0,
            'staff_recomputed' => 0,
            'staff_changed' => 0,
            'staff_skipped' => 0,
        ];

        DB::beginTransaction();

        try {
            SalaryStructureRate::query()
                ->with('salaryScale')
                ->whereHas('salaryScale', fn ($query) => $query->whereIn('code', ['GL', 'CH', 'CM']))
                ->orderBy('id')
                ->chunkById(200, function ($rates) use ($hazardType, &$summary): void {
                    foreach ($rates as $rate) {
                        $amount = $this->hazardAmountFor(
                            (string) $rate->salaryScale?->code,
                            (int) $rate->level,
                        );

                        if ($amount === null) {
                            $summary['unsupported_rates_skipped']++;
                            continue;
                        }

                        $summary['rates_matched']++;

                        $rateAllowance = SalaryStructureRateAllowance::query()->firstOrNew([
                            'salary_structure_rate_id' => $rate->id,
                            'allowance_type_id' => $hazardType->id,
                        ]);

                        if ($rateAllowance->exists && round((float) $rateAllowance->amount, 2) === $amount && $rateAllowance->status === 'active') {
                            $summary['allowance_rows_unchanged']++;
                            continue;
                        }

                        $wasExisting = $rateAllowance->exists;
                        $rateAllowance->fill([
                            'amount' => $amount,
                            'status' => 'active',
                        ])->save();

                        $summary[$wasExisting ? 'allowance_rows_updated' : 'allowance_rows_created']++;
                    }
                });

            if (! $dryRun && $recomputeCurrentStaff) {
                $this->recomputeAffectedStaff($staffRecomputeService, $summary);
            }

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (\Throwable $throwable) {
            DB::rollBack();
            throw $throwable;
        }

        $this->table(['Metric', 'Value'], collect($summary)->map(fn ($value, $key): array => [
            Str::of($key)->replace('_', ' ')->title()->toString(),
            $value,
        ])->values()->all());

        $this->components->info($dryRun ? 'Dry run complete. No records were committed.' : 'Hazard allowance rate review applied.');

        return self::SUCCESS;
    }

    protected function hazardAllowanceType(AllowanceTypeProvisioningService $allowanceTypeProvisioningService): ?AllowanceType
    {
        return $allowanceTypeProvisioningService
            ->ensureGlobal(['hazard'])['types']
            ->firstWhere('code', 'hazard');
    }

    protected function hazardAmountFor(string $scaleCode, int $level): ?float
    {
        return match (strtoupper($scaleCode)) {
            'GL' => 16000.0,
            'CH' => match (true) {
                $level >= 1 && $level <= 5 => 16000.0,
                $level >= 6 && $level <= 12 => 32000.0,
                $level >= 13 && $level <= 15 => 34000.0,
                default => null,
            },
            'CM' => match (true) {
                $level === 1 => 30000.0,
                $level === 2 => 32000.0,
                $level >= 3 && $level <= 4 => 35000.0,
                $level >= 5 && $level <= 7 => 40000.0,
                $level === 8 => 45000.0,
                default => null,
            },
            default => null,
        };
    }

    /**
     * @param  array<string, int>  $summary
     */
    protected function recomputeAffectedStaff(StaffRecomputeService $staffRecomputeService, array &$summary): void
    {
        Staff::query()
            ->with(['currentSalaryPlacement.salaryScale', 'allowanceAssignments.allowanceType'])
            ->whereHas('currentSalaryPlacement.salaryScale', fn ($query) => $query->whereIn('code', ['GL', 'CH', 'CM']))
            ->orderBy('id')
            ->chunkById(200, function ($staffRows) use ($staffRecomputeService, &$summary): void {
                foreach ($staffRows as $staff) {
                    $result = $staffRecomputeService->recomputeSalary($staff);
                    $status = (string) ($result['status'] ?? 'unknown');

                    $summary['staff_recomputed']++;
                    $summary['staff_changed'] += ($result['changed'] ?? false) ? 1 : 0;
                    $summary['staff_skipped'] += in_array($status, ['missing_current_placement', 'missing_salary_rate'], true) ? 1 : 0;
                }
            });
    }
}
