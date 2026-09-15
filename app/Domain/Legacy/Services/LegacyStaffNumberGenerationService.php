<?php

namespace App\Domain\Legacy\Services;

use App\Domain\Legacy\Models\LegacyStaffImportBatch;
use App\Domain\Legacy\Models\LegacyStaffImportRow;
use App\Domain\Legacy\Support\LegacyIdentifier;
use App\Domain\Organization\Models\Mda;
use App\Domain\Staff\Models\Staff;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LegacyStaffNumberGenerationService
{
    public function __construct(
        protected LegacyStaffImportIssueResolutionService $resolutionService,
    ) {}

    public function generateBatch(LegacyStaffImportBatch $batch, User $user, bool $dryRun = false): array
    {
        Gate::forUser($user)->authorize('view', $batch);
        abort_unless($user->can('resolve-staff-import-issues'), 403);

        return DB::transaction(function () use ($batch, $user, $dryRun): array {
            $batch = LegacyStaffImportBatch::query()->lockForUpdate()->findOrFail($batch->id);

            if (! in_array($batch->status, ['staged', 'completed', 'approved', 'partially_published'], true)) {
                throw ValidationException::withMessages([
                    'batch' => 'The batch must finish staging before staff numbers can be generated.',
                ]);
            }

            $result = ['eligible' => 0, 'generated' => 0, 'missing_mda' => 0, 'matched_staff' => 0];
            $rows = $user->scopeToAccessibleMdas($batch->rows()->getQuery())
                ->whereNull('published_staff_id')
                ->whereIn('status', ['staged', 'invalid'])
                ->lockForUpdate();

            $rows->chunkById(200, function ($rows) use ($user, $dryRun, &$result): void {
                foreach ($rows as $row) {
                    $staffNumber = LegacyIdentifier::normalize($row->staff_number);
                    if ($staffNumber !== null && ! Str::startsWith(Str::upper($staffNumber), 'PROV-')) {
                        continue;
                    }

                    if ($row->mda_id === null) {
                        $result['missing_mda']++;

                        continue;
                    }

                    Gate::forUser($user)->authorize('resolveMapping', $row);

                    // An existing staff match needs review to preserve its canonical identifier.
                    if ($row->matched_staff_id !== null) {
                        $result['matched_staff']++;

                        continue;
                    }

                    $result['eligible']++;
                    if ($dryRun) {
                        continue;
                    }

                    // Serialize generated-number reservations within this MDA across batches.
                    $mda = Mda::query()->lockForUpdate()->findOrFail($row->mda_id);
                    $code = Str::upper(preg_replace('/[^a-zA-Z0-9]/', '', $mda->code ?? '') ?: (string) $mda->id);
                    $base = 'SYS-'.substr($code, 0, 30).'-'.str_pad((string) $row->id, 6, '0', STR_PAD_LEFT);
                    $candidate = $base;
                    $suffix = 2;

                    while ($this->numberExists($row, $candidate)) {
                        $candidate = $base.'-'.$suffix++;
                    }

                    $this->resolutionService->resolveIdentifier(
                        $row,
                        $candidate,
                        $user,
                        'System staff number generated during batch review. Original CNO/PSN values are preserved.',
                        generated: true,
                    );
                    $result['generated']++;
                }
            });

            return $result;
        });
    }

    protected function numberExists(LegacyStaffImportRow $row, string $number): bool
    {
        return Staff::query()->forMda((int) $row->mda_id)->withTrashed()
            ->where('staff_number', $number)->exists()
            || LegacyStaffImportRow::query()->where('mda_id', $row->mda_id)
                ->where('staff_number', $number)->whereKeyNot($row->id)->exists();
    }
}
