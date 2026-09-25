<?php

namespace Tests\Feature;

use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Mda;
use App\Domain\Promotion\Models\PromotionApplication;
use App\Domain\Promotion\Models\PromotionCycle;
use App\Domain\Promotion\Models\PromotionSitting;
use App\Domain\Staff\Models\Cadre;
use App\Domain\Staff\Models\Rank;
use App\Domain\Staff\Models\SalaryScale;
use App\Domain\Staff\Models\Staff;
use App\Domain\Staff\Models\StaffEmployment;
use App\Domain\Staff\Models\StaffSalaryPlacement;
use App\Models\User;
use App\Models\UserAccessScope;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionDepartmentAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_department_scoped_user_only_sees_promotion_applications_for_assigned_department(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $mda = Mda::query()->create(['code' => 'HMB', 'name' => 'Hospital Management Board', 'status' => 'active']);
        $departmentA = Department::query()->create(['mda_id' => $mda->id, 'code' => 'NUR', 'name' => 'NURSING', 'status' => 'active']);
        $departmentB = Department::query()->create(['mda_id' => $mda->id, 'code' => 'MED', 'name' => 'MEDICAL', 'status' => 'active']);
        $scale = SalaryScale::query()->firstOrCreate(
            ['code' => 'PROMOGL'],
            ['name' => 'PROMOTION GRADE LEVEL', 'min_level' => 1, 'max_level' => 17, 'min_step' => 1, 'max_step' => 15, 'status' => 'active'],
        );
        $rankA = $this->rankFor($departmentA, $scale, 'Nursing Officer');
        $rankB = $this->rankFor($departmentB, $scale, 'Medical Officer');
        $staffA = $this->staffFor($mda, $departmentA, $rankA, $scale, 'STF-NUR', 'Nursing Applicant');
        $staffB = $this->staffFor($mda, $departmentB, $rankB, $scale, 'STF-MED', 'Medical Applicant');
        $creator = User::factory()->mdaUser($mda)->create();

        $cycle = PromotionCycle::query()->create([
            'mda_id' => $mda->id,
            'title' => '2026 Promotion',
            'year' => 2026,
            'status' => 'open',
            'created_by' => $creator->id,
        ]);
        $sitting = PromotionSitting::query()->create([
            'cycle_id' => $cycle->id,
            'mda_id' => $mda->id,
            'title' => 'Main Sitting',
            'sitting_date' => '2026-06-01',
            'status' => 'draft',
            'created_by' => $creator->id,
        ]);

        $applicationA = $this->applicationFor($cycle, $sitting, $mda, $staffA, $rankA, $scale);
        $applicationB = $this->applicationFor($cycle, $sitting, $mda, $staffB, $rankB, $scale);

        $user = User::factory()->mdaUser($mda)->create();
        $user->assignRole('MDA Admin');
        UserAccessScope::query()->create([
            'user_id' => $user->id,
            'scope_type' => 'department',
            'mda_id' => $mda->id,
            'department_id' => $departmentA->id,
        ]);

        $cycleResponse = $this->actingAs($user)
            ->getJson('/api/promotion-cycles/'.$cycle->id)
            ->assertOk();

        $this->assertSame([$applicationA->id], array_column($cycleResponse->json('data.applications'), 'id'));
        $this->assertStringNotContainsString('Medical Applicant', json_encode($cycleResponse->json('data')));

        $sittingResponse = $this->actingAs($user)
            ->getJson('/api/promotion-sittings/'.$sitting->id)
            ->assertOk();

        $this->assertSame([$applicationA->id], array_column($sittingResponse->json('data.applications'), 'id'));
        $this->assertStringNotContainsString('Medical Applicant', json_encode($sittingResponse->json('data')));

        $this->actingAs($user)
            ->postJson('/api/promotion-applications/'.$applicationB->id.'/screen', [
                'staff_id' => $staffB->id,
                'proposed_rank_id' => $rankB->id,
                'proposed_salary_scale_id' => $scale->id,
                'proposed_level' => 10,
                'proposed_step' => 5,
            ])
            ->assertForbidden();
    }

    protected function rankFor(Department $department, SalaryScale $scale, string $name): Rank
    {
        $cadre = Cadre::query()->create([
            'department_id' => $department->id,
            'salary_scale_id' => $scale->id,
            'name' => $name.' Cadre',
            'status' => 'active',
        ]);

        return Rank::query()->create([
            'cadre_id' => $cadre->id,
            'salary_scale_id' => $scale->id,
            'name' => $name,
            'level' => 9,
            'status' => 'active',
        ]);
    }

    protected function staffFor(Mda $mda, Department $department, Rank $rank, SalaryScale $scale, string $staffNumber, string $fullName): Staff
    {
        $staff = Staff::withoutGlobalScopes()->create([
            'mda_id' => $mda->id,
            'staff_number' => $staffNumber,
            'surname' => explode(' ', $fullName)[0],
            'first_name' => explode(' ', $fullName)[1] ?? 'Applicant',
            'full_name' => $fullName,
            'status' => 'active',
        ]);

        StaffEmployment::query()->create([
            'staff_id' => $staff->id,
            'mda_id' => $mda->id,
            'department_id' => $department->id,
            'cadre_id' => $rank->cadre_id,
            'rank_id' => $rank->id,
            'employment_status' => 'active',
            'is_current' => true,
            'effective_from' => '2024-01-01',
        ]);

        StaffSalaryPlacement::query()->create([
            'staff_id' => $staff->id,
            'salary_scale_id' => $scale->id,
            'level' => 9,
            'step' => 4,
            'is_current' => true,
            'effective_from' => '2024-01-01',
        ]);

        return $staff;
    }

    protected function applicationFor(
        PromotionCycle $cycle,
        PromotionSitting $sitting,
        Mda $mda,
        Staff $staff,
        Rank $rank,
        SalaryScale $scale,
    ): PromotionApplication {
        return PromotionApplication::query()->create([
            'cycle_id' => $cycle->id,
            'mda_id' => $mda->id,
            'staff_id' => $staff->id,
            'application_number' => 'PR-'.$staff->staff_number,
            'staff_number' => $staff->staff_number,
            'surname' => $staff->surname,
            'first_name' => $staff->first_name,
            'current_rank_id' => $rank->id,
            'current_salary_scale_id' => $scale->id,
            'current_level' => 9,
            'current_step' => 4,
            'proposed_rank_id' => $rank->id,
            'proposed_salary_scale_id' => $scale->id,
            'proposed_level' => 10,
            'proposed_step' => 5,
            'sitting_id' => $sitting->id,
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);
    }
}
