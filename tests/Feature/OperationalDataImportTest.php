<?php

namespace Tests\Feature;

use App\Domain\Legacy\Models\LegacyStaffImportBatch;
use App\Domain\Legacy\Models\LegacyStaffImportRow;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Mda;
use App\Domain\Organization\Models\Station;
use App\Domain\Staff\Models\Cadre;
use App\Domain\Staff\Models\QualificationType;
use App\Domain\Staff\Models\Rank;
use App\Domain\Staff\Models\SalaryScale;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class OperationalDataImportTest extends TestCase
{
    use RefreshDatabase;

    protected Mda $mda;
    protected Mda $otherMda;
    protected Department $department;
    protected Department $otherDepartment;
    protected SalaryScale $scale;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->mda = Mda::query()->create(['code' => 'MOH', 'name' => 'Ministry of Health', 'status' => 'active']);
        $this->otherMda = Mda::query()->create(['code' => 'HMB', 'name' => 'Hospital Management Board', 'status' => 'active']);
        $this->department = Department::withoutGlobalScopes()->create(['mda_id' => $this->mda->id, 'code' => 'CLIN', 'name' => 'Clinical Services', 'status' => 'active']);
        $this->otherDepartment = Department::withoutGlobalScopes()->create(['mda_id' => $this->otherMda->id, 'code' => 'ADMIN', 'name' => 'Administration', 'status' => 'active']);
        $this->scale = SalaryScale::query()->create(['code' => 'CM', 'name' => 'Medical Scale', 'min_level' => 1, 'max_level' => 7, 'min_step' => 1, 'max_step' => 10, 'status' => 'active']);
        $this->user = User::factory()->mdaUser($this->mda)->create();
        $this->user->assignRole('MDA Admin');
    }

    public function test_mda_user_can_import_cadres_and_ranks_for_their_mda(): void
    {
        $this->actingAs($this->user)->postJson('/api/operational-imports/cadres', [
            'file' => $this->csv("name,department_code,salary_scale_code,description,status\nMedical Officer,CLIN,CM,Clinical cadre,active"),
        ])->assertOk();

        $cadre = Cadre::query()->where('name', 'Medical Officer')->firstOrFail();
        $this->assertSame($this->department->id, $cadre->department_id);

        $this->actingAs($this->user)->post('/api/operational-imports/ranks', [
            'file' => $this->csv("name,cadre_name,department_code,salary_scale_code,level,description,status\nSenior Medical Officer,Medical Officer,CLIN,CM,4,Senior rank,active"),
        ])->assertOk();

        $this->assertDatabaseHas('ranks', ['cadre_id' => $cadre->id, 'name' => 'Senior Medical Officer', 'level' => 4]);
    }

    public function test_mda_user_can_import_stations_for_their_mda(): void
    {
        $this->actingAs($this->user)->postJson('/api/operational-imports/stations', [
            'file' => $this->csv("code,name,description,status\nHQ,Headquarters,Main station,active"),
        ])->assertOk()
            ->assertJsonPath('data.created', 1);

        $station = Station::withoutGlobalScopes()->where('code', 'HQ')->firstOrFail();
        $this->assertSame($this->mda->id, $station->mda_id);

        $this->actingAs($this->user)->postJson('/api/operational-imports/stations', [
            'file' => $this->csv("code,name,description,status\nHQ,Central Headquarters,Updated station,active"),
        ])->assertOk()
            ->assertJsonPath('data.skipped', 1);

        $this->assertDatabaseHas('stations', [
            'mda_id' => $this->mda->id,
            'code' => 'HQ',
            'name' => 'Headquarters',
        ]);

        $this->actingAs($this->user)->postJson('/api/operational-imports/stations', [
            'file' => $this->csv("code,name,description,status\nHQ,Headquarters,Main station,active"),
        ])->assertOk()
            ->assertJsonPath('data.skipped', 1);
    }

    public function test_mda_user_cannot_import_a_station_for_another_mda(): void
    {
        $this->actingAs($this->user)->postJson('/api/operational-imports/stations', [
            'file' => $this->csv("code,name,mda_code,status\nHMB-HQ,HMB Headquarters,HMB,active"),
        ])->assertUnprocessable();

        $this->assertDatabaseMissing('stations', ['code' => 'HMB-HQ']);
    }

    public function test_user_can_import_highest_qualification_types(): void
    {
        $this->actingAs($this->user)->postJson('/api/operational-imports/highest-qualifications', [
            'file' => $this->csv("code,name,description,status\nMBBS,Bachelor of Medicine,Medical degree,active"),
        ])->assertOk()
            ->assertJsonPath('data.created', 1);

        $this->actingAs($this->user)->postJson('/api/operational-imports/highest-qualifications', [
            'file' => $this->csv("code,name,description,status\nmbbs,Bachelor of Medicine and Surgery,Updated degree,active"),
        ])->assertOk()
            ->assertJsonPath('data.skipped', 1);

        $this->assertDatabaseCount('qualification_types', 1);
        $this->assertDatabaseHas('qualification_types', [
            'code' => 'MBBS',
            'name' => 'Bachelor of Medicine',
        ]);

        $this->actingAs($this->user)->postJson('/api/operational-imports/highest-qualifications', [
            'file' => $this->csv("code,name,description,status\nMBBS,Bachelor of Medicine,Medical degree,active"),
        ])->assertOk()
            ->assertJsonPath('data.skipped', 1);
    }

    public function test_reimporting_existing_cadres_and_ranks_skips_unchanged_rows(): void
    {
        $cadreFile = "name,department_code,salary_scale_code,description,status\nMedical Officer,CLIN,CM,Clinical cadre,active";
        $rankFile = "name,cadre_name,department_code,salary_scale_code,level,description,status\nSenior Medical Officer,Medical Officer,CLIN,CM,4,Senior rank,active";

        $this->actingAs($this->user)->postJson('/api/operational-imports/cadres', ['file' => $this->csv($cadreFile)])->assertOk();
        $this->actingAs($this->user)->postJson('/api/operational-imports/ranks', ['file' => $this->csv($rankFile)])->assertOk();

        $this->actingAs($this->user)->postJson('/api/operational-imports/cadres', [
            'file' => $this->csv($cadreFile),
        ])->assertOk()->assertJsonPath('data.skipped', 1);

        $this->actingAs($this->user)->postJson('/api/operational-imports/ranks', [
            'file' => $this->csv($rankFile),
        ])->assertOk()->assertJsonPath('data.skipped', 1);
    }

    public function test_same_cadre_name_and_scale_can_exist_in_different_departments(): void
    {
        $otherDepartment = Department::withoutGlobalScopes()->create([
            'mda_id' => $this->mda->id,
            'code' => 'PUBLIC',
            'name' => 'Public Health',
            'status' => 'active',
        ]);

        $this->actingAs($this->user)->postJson('/api/operational-imports/cadres', [
            'file' => $this->csv("name,department_code,salary_scale_code,status\nMedical Officer,CLIN,CM,active\nMedical Officer,PUBLIC,CM,active"),
        ])->assertOk()
            ->assertJsonPath('data.created', 2);

        $this->assertDatabaseHas('cadres', [
            'department_id' => $this->department->id,
            'salary_scale_id' => $this->scale->id,
            'name' => 'Medical Officer',
        ]);
        $this->assertDatabaseHas('cadres', [
            'department_id' => $otherDepartment->id,
            'salary_scale_id' => $this->scale->id,
            'name' => 'Medical Officer',
        ]);

        $this->actingAs($this->user)->postJson('/api/operational-imports/ranks', [
            'file' => $this->csv("name,cadre_name,department_code,salary_scale_code,level,status\nSenior Medical Officer,Medical Officer,PUBLIC,CM,4,active"),
        ])->assertOk();

        $publicHealthCadre = Cadre::query()->where('department_id', $otherDepartment->id)->firstOrFail();
        $this->assertDatabaseHas('ranks', [
            'cadre_id' => $publicHealthCadre->id,
            'name' => 'Senior Medical Officer',
            'level' => 4,
        ]);
    }

    public function test_mda_user_cannot_import_a_cadre_for_another_mda(): void
    {
        $this->actingAs($this->user)->postJson('/api/operational-imports/cadres', [
            'file' => $this->csv("name,department_code,salary_scale_code,status\nAdministrative Officer,ADMIN,CM,active"),
        ])->assertUnprocessable();

        $this->assertDatabaseMissing('cadres', ['name' => 'Administrative Officer']);
    }

    public function test_uploaded_staff_list_is_forced_into_users_mda_and_staged_for_review(): void
    {
        $cadre = Cadre::query()->create(['department_id' => $this->department->id, 'salary_scale_id' => $this->scale->id, 'name' => 'Medical Officer', 'status' => 'active']);
        Rank::query()->create(['cadre_id' => $cadre->id, 'salary_scale_id' => $this->scale->id, 'name' => 'Senior Medical Officer', 'level' => 4, 'status' => 'active']);

        $response = $this->actingAs($this->user)->post('/api/operational-imports/staff-list', [
            'file' => $this->csv("cno,psn,name,sex,dob,mda,department,cadre,rank,salary_scale,level,step\nC001,P001,Officer One,female,1985-01-01,MOH,Clinical Services,Medical Officer,Senior Medical Officer,CM,4,1"),
        ])->assertOk();

        $batch = LegacyStaffImportBatch::query()->findOrFail($response->json('data.batch_id'));
        $row = LegacyStaffImportRow::query()->where('batch_id', $batch->id)->firstOrFail();

        $this->assertSame('staff_list_upload', $batch->source_table);
        $this->assertSame($this->mda->id, $row->mda_id);
        $this->assertSame('staged', $row->status);
    }

    public function test_mda_user_cannot_upload_staff_for_another_mda(): void
    {
        $this->actingAs($this->user)->postJson('/api/operational-imports/staff-list', [
            'file' => $this->csv("cno,psn,name,mda\nC002,P002,Hidden Officer,HMB"),
        ])->assertUnprocessable();

        $this->assertDatabaseCount('legacy_staff_import_batches', 0);
    }

    public function test_templates_can_be_downloaded(): void
    {
        foreach (['stations', 'highest-qualifications', 'cadres', 'ranks', 'staff-list'] as $type) {
            $this->actingAs($this->user)
                ->get("/api/operational-imports/{$type}/template")
                ->assertOk()
                ->assertHeader('content-disposition');
        }
    }

    public function test_user_without_import_permission_cannot_upload_or_download_templates(): void
    {
        $user = User::factory()->mdaUser($this->mda)->create();

        $this->actingAs($user)
            ->post('/api/operational-imports/cadres', ['file' => $this->csv("name\nBlocked")])
            ->assertForbidden();

        $this->actingAs($user)
            ->get('/api/operational-imports/cadres/template')
            ->assertForbidden();
    }

    protected function csv(string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('import.csv', $content);
    }
}
