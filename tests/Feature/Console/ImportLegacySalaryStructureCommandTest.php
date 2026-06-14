<?php

namespace Tests\Feature\Console;

use App\Domain\Staff\Models\AllowanceType;
use App\Domain\Staff\Models\SalaryScale;
use App\Domain\Staff\Models\SalaryStructureRate;
use App\Domain\Staff\Models\SalaryStructureRateAllowance;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ImportLegacySalaryStructureCommandTest extends TestCase
{
    use RefreshDatabase;

    protected string $legacyDatabasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->legacyDatabasePath = tempnam(sys_get_temp_dir(), 'legacy-salary-');

        Config::set('database.connections.legacy', [
            'driver' => 'sqlite',
            'database' => $this->legacyDatabasePath,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        $this->createLegacySchema();
        $this->seedNewSystemReferenceData();
        $this->seedLegacyData();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        DB::disconnect('legacy');
        DB::purge('legacy');

        if (isset($this->legacyDatabasePath) && is_file($this->legacyDatabasePath)) {
            @unlink($this->legacyDatabasePath);
        }
    }

    public function test_salary_structure_import_creates_dynamic_rates_and_allowances(): void
    {
        $this
            ->artisan('legacy:import-salary-structure')
            ->expectsOutputToContain('Skipped salary structure row for scale `ZZ` because the salary scale was not found in the new system.')
            ->assertSuccessful();

        $this->assertSame(3, SalaryStructureRate::query()->count());
        $this->assertSame(13, AllowanceType::query()->count());
        $this->assertSame(6, SalaryStructureRateAllowance::query()->count());

        $chRate = SalaryStructureRate::query()
            ->whereHas('salaryScale', fn ($query) => $query->where('code', 'CH'))
            ->where('level', 7)
            ->where('step', 1)
            ->firstOrFail();

        $this->assertDatabaseHas('salary_structure_rate_allowances', [
            'salary_structure_rate_id' => $chRate->id,
            'amount' => 3500.00,
        ]);
    }

    public function test_import_is_idempotent_and_dry_run_writes_nothing(): void
    {
        $this->artisan('legacy:import-salary-structure', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, SalaryStructureRate::query()->count());
        $this->assertSame(0, SalaryStructureRateAllowance::query()->count());

        $this->artisan('legacy:import-salary-structure')->assertSuccessful();
        $firstCounts = [
            'rates' => SalaryStructureRate::query()->count(),
            'allowances' => SalaryStructureRateAllowance::query()->count(),
            'types' => AllowanceType::query()->count(),
        ];

        $this->artisan('legacy:import-salary-structure')->assertSuccessful();

        $this->assertSame($firstCounts['rates'], SalaryStructureRate::query()->count());
        $this->assertSame($firstCounts['allowances'], SalaryStructureRateAllowance::query()->count());
        $this->assertSame($firstCounts['types'], AllowanceType::query()->count());
    }

    protected function createLegacySchema(): void
    {
        Schema::connection('legacy')->create('staff_salary', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('scale', 10)->nullable();
            $table->integer('level')->nullable();
            $table->integer('step')->nullable();
            $table->decimal('basic_salary', 17, 2)->nullable();
            $table->decimal('rural_allowance', 10, 2)->nullable();
            $table->decimal('teaching_allowance', 10, 2)->nullable();
            $table->decimal('CallDoc', 10, 2)->nullable();
            $table->decimal('CallPharmLab', 10, 2)->nullable();
            $table->decimal('CallOptOdd', 10, 2)->nullable();
            $table->decimal('CallNurseOthers', 10, 2)->nullable();
            $table->decimal('shift_allowance', 10, 2)->nullable();
            $table->decimal('specialty_allowance', 10, 2)->nullable();
            $table->decimal('hazard_allowance', 10, 2)->nullable();
            $table->decimal('gross', 18, 2)->nullable();
            $table->string('status')->default('1');
        });
    }

    protected function seedNewSystemReferenceData(): void
    {
        SalaryScale::query()->create([
            'legacy_id' => 3,
            'code' => 'GL',
            'name' => 'GRADE LEVEL',
            'min_level' => 1,
            'max_level' => 17,
            'min_step' => 1,
            'max_step' => 15,
            'status' => 'active',
        ]);

        SalaryScale::query()->create([
            'legacy_id' => 2,
            'code' => 'CH',
            'name' => 'CONHESS',
            'min_level' => 1,
            'max_level' => 15,
            'min_step' => 1,
            'max_step' => 15,
            'status' => 'active',
        ]);
    }

    protected function seedLegacyData(): void
    {
        $rows = [
            [
                'scale' => 'GL',
                'level' => 1,
                'step' => 1,
                'basic_salary' => 30000.15,
                'hazard_allowance' => 5000.00,
                'gross' => 35000.15,
                'status' => '1',
            ],
            [
                'scale' => 'CH',
                'level' => 7,
                'step' => 1,
                'basic_salary' => 50000.00,
                'rural_allowance' => 1000.00,
                'teaching_allowance' => 1500.00,
                'CallPharmLab' => 3500.00,
                'shift_allowance' => 2500.00,
                'hazard_allowance' => 800.00,
                'gross' => 59300.00,
                'status' => '1',
            ],
            [
                'scale' => 'GL',
                'level' => 1,
                'step' => 2,
                'basic_salary' => 30233.48,
                'gross' => 30233.48,
                'status' => '1',
            ],
            [
                'scale' => 'ZZ',
                'level' => 1,
                'step' => 1,
                'basic_salary' => 99999.99,
                'hazard_allowance' => 100.00,
                'gross' => 100099.99,
                'status' => '1',
            ],
            [
                'scale' => 'GL',
                'level' => 3,
                'step' => 1,
                'basic_salary' => 31000.00,
                'hazard_allowance' => 200.00,
                'gross' => 31200.00,
                'status' => '0',
            ],
        ];

        foreach ($rows as $row) {
            DB::connection('legacy')->table('staff_salary')->insert($row);
        }
    }
}
