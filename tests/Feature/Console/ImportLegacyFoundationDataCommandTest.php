<?php

namespace Tests\Feature\Console;

use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Location;
use App\Domain\Organization\Models\Mda;
use App\Domain\Organization\Models\Station;
use App\Domain\Staff\Models\Cadre;
use App\Domain\Staff\Models\PromotionPolicy;
use App\Domain\Staff\Models\QualificationScaleCeiling;
use App\Domain\Staff\Models\QualificationType;
use App\Domain\Staff\Models\Rank;
use App\Domain\Staff\Models\SalaryScale;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ImportLegacyFoundationDataCommandTest extends TestCase
{
    use RefreshDatabase;

    protected string $legacyDatabasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->legacyDatabasePath = tempnam(sys_get_temp_dir(), 'legacy-ehrmis-');

        Config::set('database.connections.legacy', [
            'driver' => 'sqlite',
            'database' => $this->legacyDatabasePath,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        $this->createLegacySchema();
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

    public function test_legacy_foundation_import_command_imports_foundation_data(): void
    {
        $this
            ->artisan('legacy:import-foundation', [
                '--include-users' => true,
                '--default-password' => 'secret123',
                '--default-state' => 'Niger',
            ])
            ->expectsOutputToContain('Skipped promotion policy row 2 because salary scale `CH` was not found.')
            ->assertSuccessful();

        $this->assertDatabaseHas('mdas', [
            'code' => 'MOH',
            'name' => 'MINISTRY OF HEALTH',
        ]);

        $mda = Mda::query()->where('code', 'MOH')->firstOrFail();

        $this->assertDatabaseHas('departments', [
            'mda_id' => $mda->id,
            'name' => 'ADMIN',
        ]);

        $this->assertDatabaseHas('stations', [
            'mda_id' => $mda->id,
            'name' => 'MOH HQTR',
        ]);

        $salaryScale = SalaryScale::query()->where('code', 'GL')->firstOrFail();
        $cadre = Cadre::query()->where('name', 'ADMIN OFFICER')->firstOrFail();
        $qualificationType = QualificationType::query()->where('code', 'PHD')->firstOrFail();

        $this->assertDatabaseHas('salary_scales', [
            'code' => 'GL',
            'name' => 'GRADE LEVEL',
        ]);

        $this->assertDatabaseHas('cadres', [
            'salary_scale_id' => $salaryScale->id,
            'department_id' => $mda->departments()->where('name', 'ADMIN')->value('id'),
            'name' => 'ADMIN OFFICER',
            'legacy_department_name' => 'ADMIN',
        ]);

        $this->assertDatabaseHas('ranks', [
            'cadre_id' => $cadre->id,
            'salary_scale_id' => $salaryScale->id,
            'name' => 'A.O I',
            'level' => 9,
        ]);

        $this->assertDatabaseHas('qualification_types', [
            'code' => 'PHD',
            'name' => 'PhD',
        ]);

        $this->assertDatabaseHas('qualification_scale_ceilings', [
            'qualification_type_id' => $qualificationType->id,
            'salary_scale_id' => $salaryScale->id,
            'max_level' => 17,
        ]);

        $this->assertDatabaseHas('promotion_policies', [
            'salary_scale_id' => $salaryScale->id,
            'min_level' => 2,
            'max_level' => 6,
            'required_years' => 2,
            'policy_type' => 'normal',
        ]);

        $this->assertDatabaseHas('locations', [
            'state' => 'Niger',
            'lga' => 'CHANCHAGA',
            'town' => 'MINNA',
        ]);

        $this->assertDatabaseHas('users', [
            'email' => 'legacy-admin@example.com',
            'user_type' => 'super_admin',
        ]);

        $this->assertDatabaseHas('users', [
            'email' => 'legacy-mda@example.com',
            'mda_id' => $mda->id,
            'user_type' => 'mda_admin',
        ]);

        $this->assertSame(92, QualificationScaleCeiling::query()->count());
        $this->assertSame(1, PromotionPolicy::query()->count());
    }

    public function test_importer_is_idempotent_for_policy_and_reference_data(): void
    {
        $this->artisan('legacy:import-foundation')->assertSuccessful();
        $firstCounts = [
            'salary_scales' => SalaryScale::query()->count(),
            'cadres' => Cadre::query()->count(),
            'ranks' => Rank::query()->count(),
            'qualification_types' => QualificationType::query()->count(),
            'qualification_scale_ceilings' => QualificationScaleCeiling::query()->count(),
            'promotion_policies' => PromotionPolicy::query()->count(),
        ];

        $this->artisan('legacy:import-foundation')->assertSuccessful();

        $this->assertSame($firstCounts['salary_scales'], SalaryScale::query()->count());
        $this->assertSame($firstCounts['cadres'], Cadre::query()->count());
        $this->assertSame($firstCounts['ranks'], Rank::query()->count());
        $this->assertSame($firstCounts['qualification_types'], QualificationType::query()->count());
        $this->assertSame($firstCounts['qualification_scale_ceilings'], QualificationScaleCeiling::query()->count());
        $this->assertSame($firstCounts['promotion_policies'], PromotionPolicy::query()->count());
    }

    public function test_importer_seeds_builtin_promotion_policies_when_legacy_table_is_missing(): void
    {
        Schema::connection('legacy')->drop('promotion_years');

        $this
            ->artisan('legacy:import-foundation')
            ->assertSuccessful();

        $this->assertSame(8, PromotionPolicy::query()->count());
        $this->assertDatabaseHas('promotion_policies', [
            'min_level' => 2,
            'max_level' => 6,
            'required_years' => 2,
            'policy_type' => 'normal',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('promotion_policies', [
            'min_level' => 1,
            'max_level' => 5,
            'required_years' => 2,
            'policy_type' => 'normal',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('promotion_policies', [
            'min_level' => 5,
            'max_level' => 7,
            'required_years' => 4,
            'policy_type' => 'normal',
            'status' => 'active',
        ]);
    }

    public function test_station_alias_lookup_imports_abbreviated_legacy_station_names(): void
    {
        $legacy = DB::connection('legacy');

        $legacy->table('staff_list')->insert([
            'mda' => 'HOSPITAL MANAGEMENT BOARD',
            'department' => 'MEDICAL',
            'station' => 'GH JBANM',
            'location' => 'MINNA',
            'status' => '1',
        ]);

        $legacy->table('tbl_stations')->insert([
            'id' => 28,
            'station' => 'JBANM',
            'location' => 'MINNA',
            'lga_name' => 'BORGU',
            'address' => '',
            'status' => '1',
        ]);

        $this->artisan('legacy:import-foundation')->assertSuccessful();

        $hmb = Mda::query()->where('code', 'HMB')->firstOrFail();

        $this->assertDatabaseHas('stations', [
            'mda_id' => $hmb->id,
            'name' => 'JBANM',
        ]);
    }

    public function test_dry_run_does_not_persist_imported_records(): void
    {
        $this
            ->artisan('legacy:import-foundation', [
                '--include-users' => true,
                '--dry-run' => true,
                '--default-password' => 'secret123',
            ])
            ->assertSuccessful();

        $this->assertSame(0, Mda::query()->count());
        $this->assertSame(0, Department::query()->count());
        $this->assertSame(0, Station::query()->count());
        $this->assertSame(0, Location::query()->count());
        $this->assertSame(0, SalaryScale::query()->count());
        $this->assertSame(0, Cadre::query()->count());
        $this->assertSame(0, Rank::query()->count());
        $this->assertSame(13, QualificationType::query()->unified()->count());
        $this->assertSame(0, QualificationScaleCeiling::query()->count());
        $this->assertSame(0, PromotionPolicy::query()->count());
        $this->assertSame(0, User::query()->count());
    }

    protected function createLegacySchema(): void
    {
        Schema::connection('legacy')->create('tbl_mda', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->string('mda');
            $table->dateTime('created_at')->nullable();
            $table->string('status')->default('1');
            $table->string('full_name');
            $table->string('code');
        });

        Schema::connection('legacy')->create('departments', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->string('department');
            $table->string('department_code');
            $table->string('status')->default('1');
        });

        Schema::connection('legacy')->create('staff_list', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('mda')->nullable();
            $table->string('department')->nullable();
            $table->string('station')->nullable();
            $table->string('location')->nullable();
            $table->string('status')->default('1');
        });

        Schema::connection('legacy')->create('tbl_stations', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->string('station');
            $table->string('location')->nullable();
            $table->integer('lga')->nullable();
            $table->string('lga_name')->nullable();
            $table->string('address')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->string('status')->default('1');
        });

        Schema::connection('legacy')->create('tbl_salary_scale', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->string('salary_scale');
            $table->string('code')->nullable();
            $table->integer('min_level')->default(0);
            $table->integer('max_level')->default(0);
            $table->integer('min_step')->default(0);
            $table->integer('max_step')->default(0);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->string('status')->default('1');
        });

        Schema::connection('legacy')->create('tbl_cadre', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->string('cadre');
            $table->integer('salary_scale_id');
            $table->string('department')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->string('status')->default('1');
        });

        Schema::connection('legacy')->create('tbl_rank', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->string('rank')->nullable();
            $table->string('cadre_name')->nullable();
            $table->integer('cadre')->nullable();
            $table->integer('level')->nullable();
            $table->string('salary_scale_code')->nullable();
            $table->integer('salary_scale_id')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
            $table->string('status')->default('1');
        });

        Schema::connection('legacy')->create('certificate_bar', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->string('certificate');
            $table->integer('CH')->default(0);
            $table->integer('GL')->default(0);
            $table->integer('CM')->default(0);
            $table->integer('SG')->default(0);
            $table->string('status')->default('1');
        });

        Schema::connection('legacy')->create('promotion_years', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->string('scale');
            $table->integer('min_level');
            $table->integer('max_level');
            $table->integer('year');
            $table->string('status')->default('1');
        });

        Schema::connection('legacy')->create('users', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->string('role')->nullable();
            $table->string('userId');
            $table->string('first_name')->nullable();
            $table->string('surname')->nullable();
            $table->string('other_name')->nullable();
            $table->string('email');
            $table->string('phone_number')->nullable();
            $table->string('email_verified_at')->nullable();
            $table->string('password');
            $table->string('access')->nullable();
            $table->string('mda')->nullable();
            $table->string('remember_token')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->string('activation_status')->default('0');
            $table->string('pwd_status')->default('0');
            $table->string('status')->default('1');
        });
    }

    protected function seedLegacyData(): void
    {
        $legacy = DB::connection('legacy');

        $legacy->table('tbl_mda')->insert([
            ['id' => 4, 'mda' => 'MINISTRY OF HEALTH', 'full_name' => 'MINISTRY OF HEALTH', 'code' => 'MOH', 'status' => '1'],
            ['id' => 2, 'mda' => 'HOSPITAL MANAGEMENT BOARD', 'full_name' => 'HOSPITAL MANAGEMENT BOARD', 'code' => 'HMB', 'status' => '1'],
        ]);

        $legacy->table('departments')->insert([
            ['id' => 1, 'department' => 'ADMIN', 'department_code' => 'eHRIMS/ADM', 'status' => '1'],
            ['id' => 2, 'department' => 'MEDICAL', 'department_code' => 'eHRIMS/MED', 'status' => '1'],
        ]);

        $legacy->table('staff_list')->insert([
            ['mda' => 'MINISTRY OF HEALTH', 'department' => 'ADMIN', 'station' => 'MOH HQTR', 'location' => 'MINNA', 'status' => '1'],
            ['mda' => 'MINISTRY OF HEALTH', 'department' => 'MEDICAL', 'station' => 'GH MINNA', 'location' => 'MINNA', 'status' => '1'],
            ['mda' => 'HOSPITAL MANAGEMENT BOARD', 'department' => 'MEDICAL', 'station' => 'GH MINNA', 'location' => 'MINNA', 'status' => '1'],
        ]);

        $legacy->table('tbl_stations')->insert([
            ['id' => 27, 'station' => 'MOH HQTR', 'location' => 'MINNA', 'lga_name' => 'CHANCHAGA', 'address' => '', 'status' => '1'],
            ['id' => 15, 'station' => 'GH MINNA', 'location' => 'MINNA', 'lga_name' => 'CHANCHAGA', 'address' => '', 'status' => '1'],
        ]);

        $legacy->table('tbl_salary_scale')->insert([
            ['id' => 1, 'salary_scale' => 'CONMESS', 'code' => 'CM', 'min_level' => 1, 'max_level' => 8, 'min_step' => 1, 'max_step' => 11, 'status' => '1'],
            ['id' => 3, 'salary_scale' => 'GRADE LEVEL', 'code' => 'GL', 'min_level' => 1, 'max_level' => 17, 'min_step' => 1, 'max_step' => 15, 'status' => '1'],
        ]);

        $legacy->table('tbl_cadre')->insert([
            ['id' => 2, 'cadre' => 'ADMIN OFFICER', 'salary_scale_id' => 3, 'department' => 'ADMIN', 'status' => '1'],
            ['id' => 21, 'cadre' => 'MEDICAL OFFICER', 'salary_scale_id' => 1, 'department' => 'MEDICAL', 'status' => '1'],
        ]);

        $legacy->table('tbl_rank')->insert([
            ['id' => 10, 'rank' => 'A.O II', 'cadre_name' => 'ADMIN OFFICER', 'cadre' => 2, 'level' => 8, 'salary_scale_code' => 'GL', 'salary_scale_id' => 3, 'status' => '1'],
            ['id' => 11, 'rank' => 'A.O I', 'cadre_name' => 'ADMIN OFFICER', 'cadre' => 2, 'level' => 9, 'salary_scale_code' => 'GL', 'salary_scale_id' => 3, 'status' => '1'],
            ['id' => 101, 'rank' => 'MEDICAL OFFICER', 'cadre_name' => 'MEDICAL OFFICER', 'cadre' => 21, 'level' => 1, 'salary_scale_code' => 'CM', 'salary_scale_id' => 1, 'status' => '1'],
        ]);

        $legacy->table('certificate_bar')->insert([
            ['id' => 1, 'certificate' => 'PhD', 'CH' => 15, 'GL' => 17, 'CM' => 7, 'SG' => 0, 'status' => '1'],
            ['id' => 2, 'certificate' => 'HND', 'CH' => 0, 'GL' => 15, 'CM' => 0, 'SG' => 0, 'status' => '1'],
        ]);

        $legacy->table('promotion_years')->insert([
            ['id' => 1, 'scale' => 'GL', 'min_level' => 2, 'max_level' => 6, 'year' => 2, 'status' => '1'],
            ['id' => 2, 'scale' => 'CH', 'min_level' => 1, 'max_level' => 5, 'year' => 2, 'status' => '1'],
        ]);

        $legacy->table('users')->insert([
            [
                'id' => 1,
                'role' => 'super_admin',
                'userId' => 'USER/0001',
                'first_name' => 'Legacy',
                'surname' => 'Admin',
                'other_name' => null,
                'email' => 'legacy-admin@example.com',
                'password' => md5('1234'),
                'access' => 'MIS',
                'mda' => null,
                'status' => '1',
            ],
            [
                'id' => 2,
                'role' => 'Director',
                'userId' => 'USER/0002',
                'first_name' => 'Legacy',
                'surname' => 'MDA',
                'other_name' => null,
                'email' => 'legacy-mda@example.com',
                'password' => md5('1234'),
                'access' => 'eHRIMS',
                'mda' => '4',
                'status' => '1',
            ],
        ]);
    }
}
