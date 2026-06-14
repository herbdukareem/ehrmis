<?php

namespace Tests\Concerns;

use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Mda;
use App\Domain\Organization\Models\Station;
use App\Domain\Staff\Models\AllowanceType;
use App\Domain\Staff\Models\Cadre;
use App\Domain\Staff\Models\PromotionPolicy;
use App\Domain\Staff\Models\QualificationType;
use App\Domain\Staff\Models\Rank;
use App\Domain\Staff\Models\SalaryScale;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait BuildsLegacyStaffImportFixtures
{
    protected string $legacyDatabasePath;

    protected function setUpLegacyStaffFixtures(): void
    {
        $this->legacyDatabasePath = tempnam(sys_get_temp_dir(), 'legacy-staff-');

        Config::set('database.connections.legacy', [
            'driver' => 'sqlite',
            'database' => $this->legacyDatabasePath,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        $this->createLegacyStaffSchema();
        $this->seedReferenceData();
        $this->seedLegacyStaffData();
    }

    protected function tearDownLegacyStaffFixtures(): void
    {
        DB::disconnect('legacy');
        DB::purge('legacy');

        if (isset($this->legacyDatabasePath) && is_file($this->legacyDatabasePath)) {
            @unlink($this->legacyDatabasePath);
        }
    }

    protected function createLegacyStaffSchema(): void
    {
        Schema::connection('legacy')->create('staff_list', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('cno')->nullable();
            $table->string('name')->nullable();
            $table->string('psn')->nullable();
            $table->string('sex')->nullable();
            $table->string('staff_category')->nullable();
            $table->string('lga')->nullable();
            $table->string('file_no')->nullable();
            $table->string('mda')->nullable();
            $table->string('station')->nullable();
            $table->string('location')->nullable();
            $table->string('salary_scale')->nullable();
            $table->string('initial_cadre')->nullable();
            $table->string('initial_rank')->nullable();
            $table->integer('initial_level')->nullable();
            $table->integer('initial_step')->nullable();
            $table->string('level_step')->nullable();
            $table->string('qualification')->nullable();
            $table->string('highest_qualification')->nullable();
            $table->string('specialization')->nullable();
            $table->string('department')->nullable();
            $table->string('date_of_birth')->nullable();
            $table->string('date_of_first_appointment')->nullable();
            $table->string('date_of_last_promotion')->nullable();
            $table->string('consultant')->nullable();
            $table->string('date_of_retirement_by_age')->nullable();
            $table->string('date_of_retirement_by_service')->nullable();
            $table->date('dob')->nullable();
            $table->date('dfa')->nullable();
            $table->date('dpa')->nullable();
            $table->date('edor')->nullable();
            $table->date('edor_age')->nullable();
            $table->date('edor_service')->nullable();
            $table->integer('specialist_')->nullable();
            $table->string('cadre')->nullable();
            $table->string('rank')->nullable();
            $table->integer('level')->nullable();
            $table->integer('step')->nullable();
            $table->integer('call_initial')->nullable();
            $table->integer('shift_initial')->nullable();
            $table->integer('hazard_initial')->nullable();
            $table->integer('teaching_initial')->nullable();
            $table->integer('specialist_initial')->nullable();
            $table->integer('rural_initial')->nullable();
            $table->string('call_')->nullable();
            $table->integer('shift_')->nullable();
            $table->integer('teaching_')->nullable();
            $table->integer('hazard_')->nullable();
            $table->integer('rural_')->nullable();
            $table->integer('domestic_')->nullable();
            $table->double('call_value')->nullable();
            $table->double('shift_value')->nullable();
            $table->double('teaching_value')->nullable();
            $table->double('hazard_value')->nullable();
            $table->double('rural_value')->nullable();
            $table->double('specialist_value')->nullable();
            $table->double('domestic_value')->nullable();
            $table->double('basic_salary')->nullable();
            $table->double('gross')->nullable();
            $table->string('next_promotion_date')->nullable();
            $table->boolean('is_retired')->default(false);
            $table->integer('duplicate')->default(0);
            $table->integer('appropriate_promotion')->default(1);
            $table->string('cno_psn')->nullable();
            $table->string('status')->default('1');
        });

        Schema::connection('legacy')->create('master_staff_list', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('psn')->nullable();
            $table->string('cno')->nullable();
            $table->string('first_name')->nullable();
            $table->string('other_name')->nullable();
            $table->string('surname')->nullable();
            $table->string('date_of_birth')->nullable();
            $table->string('sex')->nullable();
            $table->string('lga')->nullable();
            $table->string('state')->nullable();
            $table->string('mda')->nullable();
            $table->string('department')->nullable();
            $table->string('unit')->nullable();
            $table->string('station')->nullable();
            $table->string('location')->nullable();
            $table->string('date_of_first_appointment')->nullable();
            $table->string('date_of_last_promotion')->nullable();
            $table->string('date_of_retirement_by_age')->nullable();
            $table->string('date_of_retirement_by_service')->nullable();
            $table->string('appointment_type')->nullable();
            $table->string('cadre')->nullable();
            $table->string('actual_cadre')->nullable();
            $table->string('rank')->nullable();
            $table->string('qualifications')->nullable();
            $table->string('highest_qualification')->nullable();
            $table->string('area_of_specialization')->nullable();
            $table->string('contract_staff')->nullable();
            $table->string('consultant')->nullable();
            $table->string('professional_body')->nullable();
            $table->string('salary_scale')->nullable();
            $table->string('salary_scale_code')->nullable();
            $table->integer('salary_scale_id')->nullable();
            $table->string('level')->nullable();
            $table->integer('step')->nullable();
            $table->string('call_allowance')->nullable();
            $table->string('actual_call_allowance')->nullable();
            $table->string('shift')->nullable();
            $table->string('hazard')->nullable();
            $table->string('teaching')->nullable();
            $table->string('actual_teaching_allowance')->nullable();
            $table->string('sepecialist')->nullable();
            $table->string('rural_allowance')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('account_number')->nullable();
            $table->string('sort_code')->nullable();
            $table->string('bvn')->nullable();
            $table->string('hmd_file_number')->nullable();
            $table->string('phone_number')->nullable();
            $table->boolean('is_retired')->default(false);
            $table->string('status')->default('1');
        });
    }

    protected function seedReferenceData(): void
    {
        $moh = Mda::query()->create([
            'code' => 'MOH',
            'name' => 'MINISTRY OF HEALTH',
            'status' => 'active',
        ]);

        $hmb = Mda::query()->create([
            'code' => 'HMB',
            'name' => 'HOSPITAL MANAGEMENT BOARD',
            'status' => 'active',
        ]);

        Department::query()->create([
            'mda_id' => $moh->id,
            'code' => 'ADMIN',
            'name' => 'ADMIN',
            'status' => 'active',
        ]);

        Department::query()->create([
            'mda_id' => $hmb->id,
            'code' => 'ADMIN',
            'name' => 'ADMIN',
            'status' => 'active',
        ]);

        Station::withoutGlobalScopes()->create([
            'mda_id' => $moh->id,
            'code' => 'MOH_HQTR',
            'name' => 'MOH HQTR',
            'status' => 'active',
        ]);

        Station::withoutGlobalScopes()->create([
            'mda_id' => $hmb->id,
            'code' => 'HMB_HQTRS',
            'name' => 'HMB HQTRS',
            'status' => 'active',
        ]);

        $gl = SalaryScale::query()->create([
            'legacy_id' => 3,
            'code' => 'GL',
            'name' => 'GRADE LEVEL',
            'min_level' => 1,
            'max_level' => 17,
            'min_step' => 1,
            'max_step' => 15,
            'status' => 'active',
        ]);

        $ch = SalaryScale::query()->create([
            'legacy_id' => 2,
            'code' => 'CH',
            'name' => 'CONHESS',
            'min_level' => 1,
            'max_level' => 15,
            'min_step' => 1,
            'max_step' => 15,
            'status' => 'active',
        ]);

        $adminCadre = Cadre::query()->create([
            'legacy_id' => 2,
            'salary_scale_id' => $gl->id,
            'department_id' => Department::query()->where('mda_id', $moh->id)->where('name', 'ADMIN')->value('id'),
            'name' => 'ADMIN OFFICER',
            'legacy_department_name' => 'ADMIN',
            'status' => 'active',
        ]);

        Cadre::query()->create([
            'legacy_id' => 21,
            'salary_scale_id' => $ch->id,
            'department_id' => Department::query()->where('mda_id', $hmb->id)->where('name', 'ADMIN')->value('id'),
            'name' => 'PHARMACIST',
            'legacy_department_name' => 'ADMIN',
            'status' => 'active',
        ]);

        Rank::query()->create([
            'legacy_id' => 11,
            'cadre_id' => $adminCadre->id,
            'salary_scale_id' => $gl->id,
            'name' => 'A.O I',
            'level' => 9,
            'status' => 'active',
        ]);

        Rank::query()->create([
            'legacy_id' => 10,
            'cadre_id' => $adminCadre->id,
            'salary_scale_id' => $gl->id,
            'name' => 'A.O II',
            'level' => 8,
            'status' => 'active',
        ]);

        QualificationType::query()->create([
            'code' => 'HND',
            'name' => 'HND',
            'status' => 'active',
        ]);

        QualificationType::query()->create([
            'code' => 'PHD',
            'name' => 'PHD',
            'status' => 'active',
        ]);

        foreach ([
            ['code' => 'rural', 'name' => 'Rural Allowance'],
            ['code' => 'teaching', 'name' => 'Teaching Allowance'],
            ['code' => 'call_doctor', 'name' => 'Call Allowance - Doctor'],
            ['code' => 'call_pharm_lab', 'name' => 'Call Allowance - Pharmacy/Lab'],
            ['code' => 'call_opt_odd', 'name' => 'Call Allowance - Optometry/ODD'],
            ['code' => 'call_nurse_others', 'name' => 'Call Allowance - Nurse/Others'],
            ['code' => 'shift', 'name' => 'Shift Allowance'],
            ['code' => 'specialty', 'name' => 'Specialty Allowance'],
            ['code' => 'hazard', 'name' => 'Hazard Allowance'],
            ['code' => 'domestic', 'name' => 'Domestic Allowance'],
            ['code' => 'professional', 'name' => 'Professional Allowance'],
            ['code' => 'responsibility', 'name' => 'Responsibility Allowance'],
            ['code' => 'other', 'name' => 'Other Allowance'],
        ] as $allowanceType) {
            AllowanceType::query()->create([
                'code' => $allowanceType['code'],
                'name' => $allowanceType['name'],
                'status' => 'active',
            ]);
        }

        PromotionPolicy::query()->create([
            'salary_scale_id' => $gl->id,
            'min_level' => 7,
            'max_level' => 14,
            'required_years' => 3,
            'policy_type' => 'normal',
            'status' => 'active',
        ]);
    }

    protected function seedLegacyStaffData(): void
    {
        $legacy = DB::connection('legacy');

        $staffRows = [
            [
                'id' => 1,
                'cno' => 'C001',
                'name' => 'Doe John Alpha',
                'psn' => 'P001',
                'sex' => 'Male',
                'staff_category' => 'Senior',
                'lga' => 'Chanchaga',
                'file_no' => 'F001',
                'mda' => 'MINISTRY OF HEALTH',
                'station' => 'MOH HQTR',
                'location' => 'MINNA',
                'salary_scale' => 'GL',
                'initial_rank' => 'A.O I',
                'qualification' => 'ND',
                'highest_qualification' => 'HND',
                'specialization' => 'Administration',
                'department' => 'ADMIN',
                'dob' => '1980-01-01',
                'dfa' => '2010-01-01',
                'dpa' => '2020-01-01',
                'edor' => '2040-01-01',
                'cadre' => 'ADMIN OFFICER',
                'rank' => 'A.O I',
                'level' => 9,
                'step' => 2,
                'call_' => 'CallDoc',
                'call_value' => 1200,
                'hazard_' => 1,
                'hazard_value' => 500,
                'basic_salary' => 50000,
                'gross' => 55000,
                'next_promotion_date' => '2023-01-01',
                'is_retired' => 0,
                'duplicate' => 0,
                'cno_psn' => 'C001P001',
                'status' => '1',
            ],
            [
                'id' => 2,
                'cno' => 'C002',
                'name' => 'Retired Person Beta',
                'psn' => 'P002',
                'sex' => 'Female',
                'mda' => 'MINISTRY OF HEALTH',
                'station' => 'MOH HQTR',
                'location' => 'MINNA',
                'salary_scale' => 'GL',
                'highest_qualification' => 'HND',
                'department' => 'ADMIN',
                'dob' => '1960-01-01',
                'dfa' => '1990-01-01',
                'dpa' => '2020-01-01',
                'edor' => '2020-01-01',
                'cadre' => 'ADMIN OFFICER',
                'rank' => 'A.O I',
                'level' => 9,
                'step' => 2,
                'basic_salary' => 45000,
                'gross' => 50000,
                'next_promotion_date' => '2023-01-01',
                'is_retired' => 1,
                'duplicate' => 0,
                'cno_psn' => 'C002P002',
                'status' => '1',
            ],
            [
                'id' => 3,
                'cno' => 'C003',
                'name' => 'Mismatch Jane Gamma',
                'psn' => 'P003',
                'sex' => 'Female',
                'mda' => 'MINISTRY OF HEALTH',
                'station' => 'UNKNOWN STATION',
                'location' => 'MINNA',
                'salary_scale' => 'GL',
                'highest_qualification' => 'PHD',
                'department' => 'UNKNOWN DEPARTMENT',
                'dob' => '1985-05-01',
                'dfa' => '2010-06-01',
                'dpa' => '2020-01-01',
                'edor' => '2050-01-01',
                'cadre' => 'UNKNOWN CADRE',
                'rank' => 'UNKNOWN RANK',
                'level' => 8,
                'step' => 1,
                'basic_salary' => 40000,
                'gross' => 47000,
                'next_promotion_date' => '2025-01-01',
                'is_retired' => 0,
                'duplicate' => 0,
                'cno_psn' => 'C003P003',
                'status' => '1',
            ],
            [
                'id' => 4,
                'cno' => 'C004',
                'name' => 'Invalid Date User',
                'psn' => 'P004',
                'sex' => 'Male',
                'mda' => 'MINISTRY OF HEALTH',
                'station' => 'MOH HQTR',
                'location' => 'MINNA',
                'salary_scale' => 'GL',
                'highest_qualification' => 'HND',
                'department' => 'ADMIN',
                'date_of_birth' => '32/13/2020',
                'date_of_first_appointment' => 'bad-date',
                'date_of_last_promotion' => 'wrong',
                'cadre' => 'ADMIN OFFICER',
                'rank' => 'A.O I',
                'level' => 9,
                'step' => 2,
                'next_promotion_date' => 'not-a-date',
                'is_retired' => 0,
                'duplicate' => 1,
                'cno_psn' => 'C004P004',
                'status' => '1',
            ],
            [
                'id' => 5,
                'cno' => 'C005',
                'name' => 'Board User Delta',
                'psn' => 'P005',
                'sex' => 'Male',
                'mda' => 'HOSPITAL MANAGEMENT BOARD',
                'station' => 'HMB HQTRS',
                'location' => 'MINNA',
                'salary_scale' => 'GL',
                'highest_qualification' => 'HND',
                'department' => 'ADMIN',
                'dob' => '1988-02-02',
                'dfa' => '2012-02-02',
                'dpa' => '2021-02-02',
                'edor' => '2047-02-02',
                'cadre' => 'ADMIN OFFICER',
                'rank' => 'A.O II',
                'level' => 8,
                'step' => 2,
                'basic_salary' => 42000,
                'gross' => 46000,
                'next_promotion_date' => '2024-02-02',
                'is_retired' => 0,
                'duplicate' => 0,
                'cno_psn' => 'C005P005',
                'status' => '1',
            ],
        ];

        foreach ($staffRows as $row) {
            $legacy->table('staff_list')->insert($row);
        }

        $masterRows = [
            [
                'id' => 101,
                'psn' => 'P001',
                'cno' => 'C001',
                'first_name' => 'John',
                'other_name' => 'Alpha',
                'surname' => 'Doe',
                'date_of_birth' => '1980-01-01',
                'sex' => 'Male',
                'lga' => 'Chanchaga',
                'state' => 'Niger',
                'mda' => 'MINISTRY OF HEALTH',
                'department' => 'ADMIN',
                'station' => 'MOH HQTR',
                'location' => 'MINNA',
                'date_of_first_appointment' => '2010-01-01',
                'date_of_last_promotion' => '2020-01-01',
                'date_of_retirement_by_age' => '2040-01-01',
                'cadre' => 'ADMIN OFFICER',
                'rank' => 'A.O I',
                'qualifications' => 'ND',
                'highest_qualification' => 'HND',
                'area_of_specialization' => 'Administration',
                'salary_scale' => 'GRADE LEVEL',
                'salary_scale_code' => 'GL',
                'level' => '9',
                'step' => 2,
                'call_allowance' => 'YES',
                'hazard' => 'YES',
                'hmd_file_number' => 'F001',
                'phone_number' => '08030000001',
                'is_retired' => 0,
                'status' => '1',
            ],
            [
                'id' => 102,
                'psn' => 'P002',
                'cno' => 'C002',
                'first_name' => 'Person',
                'other_name' => 'Beta',
                'surname' => 'Retired',
                'date_of_birth' => '1960-01-01',
                'sex' => 'Female',
                'lga' => 'Chanchaga',
                'state' => 'Niger',
                'mda' => 'MINISTRY OF HEALTH',
                'department' => 'ADMIN',
                'station' => 'MOH HQTR',
                'location' => 'MINNA',
                'date_of_first_appointment' => '1990-01-01',
                'date_of_last_promotion' => '2020-01-01',
                'date_of_retirement_by_age' => '2020-01-01',
                'cadre' => 'ADMIN OFFICER',
                'rank' => 'A.O I',
                'highest_qualification' => 'HND',
                'salary_scale' => 'GRADE LEVEL',
                'salary_scale_code' => 'GL',
                'level' => '9',
                'step' => 2,
                'is_retired' => 1,
                'status' => '1',
            ],
            [
                'id' => 103,
                'psn' => 'P003',
                'cno' => 'C003',
                'first_name' => 'Jane',
                'other_name' => 'Gamma',
                'surname' => 'Mismatch',
                'date_of_birth' => '1985-05-01',
                'sex' => 'Female',
                'lga' => 'Chanchaga',
                'state' => 'Niger',
                'mda' => 'MINISTRY OF HEALTH',
                'department' => 'UNKNOWN DEPARTMENT',
                'station' => 'UNKNOWN STATION',
                'location' => 'MINNA',
                'date_of_first_appointment' => '2010-06-01',
                'date_of_last_promotion' => '2020-01-01',
                'date_of_retirement_by_age' => '2045-05-01',
                'cadre' => 'UNKNOWN CADRE',
                'rank' => 'UNKNOWN RANK',
                'highest_qualification' => 'PHD',
                'salary_scale' => 'GRADE LEVEL',
                'salary_scale_code' => 'GL',
                'level' => '8',
                'step' => 1,
                'is_retired' => 0,
                'status' => '1',
            ],
            [
                'id' => 105,
                'psn' => 'P005',
                'cno' => 'C005',
                'first_name' => 'User',
                'other_name' => 'Delta',
                'surname' => 'Board',
                'date_of_birth' => '1988-02-02',
                'sex' => 'Male',
                'lga' => 'Bosso',
                'state' => 'Niger',
                'mda' => 'HOSPITAL MANAGEMENT BOARD',
                'department' => 'ADMIN',
                'station' => 'HMB HQTRS',
                'location' => 'MINNA',
                'date_of_first_appointment' => '2012-02-02',
                'date_of_last_promotion' => '2021-02-02',
                'date_of_retirement_by_age' => '2047-02-02',
                'cadre' => 'ADMIN OFFICER',
                'rank' => 'A.O II',
                'highest_qualification' => 'HND',
                'salary_scale' => 'GRADE LEVEL',
                'salary_scale_code' => 'GL',
                'level' => '8',
                'step' => 2,
                'is_retired' => 0,
                'status' => '1',
            ],
        ];

        foreach ($masterRows as $row) {
            $legacy->table('master_staff_list')->insert($row);
        }
    }
}
