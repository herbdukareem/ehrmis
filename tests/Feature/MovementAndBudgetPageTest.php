<?php

namespace Tests\Feature;

use App\Domain\Budget\Models\BudgetWorkbook;
use App\Domain\Movement\Models\MovementSummary;
use App\Domain\Movement\Models\MovementWorkbook;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Mda;
use App\Domain\Staff\Models\SalaryScale;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MovementAndBudgetPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_mda_user_only_sees_their_own_movement_and_budget_workbooks(): void
    {
        [$mdaA, $mdaB] = $this->createMdas();
        $scale = $this->createSalaryScale();

        $movementA = MovementWorkbook::query()->create([
            'mda_id' => $mdaA->id,
            'year' => 2026,
            'status' => 'approved',
        ]);

        $movementB = MovementWorkbook::query()->create([
            'mda_id' => $mdaB->id,
            'year' => 2026,
            'status' => 'approved',
        ]);

        MovementSummary::query()->create([
            'workbook_id' => $movementA->id,
            'department_id' => null,
            'salary_scale_id' => $scale->id,
            'level' => 9,
            'staff_count' => 2,
            'due_count' => 1,
            'retiring_count' => 0,
            'retired_count' => 0,
            'blocked_count' => 0,
            'current_gross_total' => 80000,
            'proposed_gross_total' => 92000,
            'variance_total' => 12000,
        ]);

        MovementSummary::query()->create([
            'workbook_id' => $movementB->id,
            'department_id' => null,
            'salary_scale_id' => $scale->id,
            'level' => 9,
            'staff_count' => 4,
            'due_count' => 2,
            'retiring_count' => 0,
            'retired_count' => 0,
            'blocked_count' => 0,
            'current_gross_total' => 160000,
            'proposed_gross_total' => 184000,
            'variance_total' => 24000,
        ]);

        $budgetA = BudgetWorkbook::query()->create([
            'mda_id' => $mdaA->id,
            'movement_workbook_id' => $movementA->id,
            'year' => 2026,
            'status' => 'draft',
        ]);

        $budgetB = BudgetWorkbook::query()->create([
            'mda_id' => $mdaB->id,
            'movement_workbook_id' => $movementB->id,
            'year' => 2027,
            'status' => 'draft',
        ]);

        $user = User::factory()->mdaUser($mdaA, 'mda_admin')->create();
        $user->assignRole('MDA Admin');

        $this->actingAs($user)
            ->get('/movement-workbooks')
            ->assertOk();

        $this->actingAs($user)
            ->get('/budget-workbooks')
            ->assertOk();

        $this->actingAs($user)
            ->getJson('/api/movement-workbooks')
            ->assertOk()
            ->assertJsonFragment(['id' => $movementA->id, 'year' => 2026])
            ->assertJsonMissing(['id' => $movementB->id]);

        $this->actingAs($user)
            ->getJson('/api/budget-workbooks')
            ->assertOk()
            ->assertJsonFragment(['id' => $budgetA->id, 'year' => 2026])
            ->assertJsonMissing(['id' => $budgetB->id]);
    }

    public function test_mda_budget_officer_cannot_create_movement_or_budget_for_another_mda(): void
    {
        [$mdaA, $mdaB] = $this->createMdas();
        $movementB = MovementWorkbook::query()->create([
            'mda_id' => $mdaB->id,
            'year' => 2026,
            'status' => 'approved',
        ]);

        $user = User::factory()->mdaUser($mdaA)->create();
        $user->assignRole('Budget Officer');

        $this->actingAs($user)
            ->postJson('/api/movement-workbooks', [
                'mda_id' => $mdaB->id,
                'name' => 'HMB Movement Sheet',
                'year' => 2026,
                'budget_year' => 2027,
                'budget_minimum_step' => 5,
            ])
            ->assertForbidden();

        $this->actingAs($user)
            ->postJson('/api/budget-workbooks', [
                'movement_workbook_id' => $movementB->id,
            ])
            ->assertForbidden();
    }

    public function test_budget_officer_can_create_a_named_movement_workbook_with_planning_fields(): void
    {
        [$mdaA] = $this->createMdas();
        $user = User::factory()->mdaUser($mdaA)->create();
        $user->assignRole('Budget Officer');

        $response = $this->actingAs($user)
            ->postJson('/api/movement-workbooks', [
                'mda_id' => $mdaA->id,
                'name' => '2027 Full Details',
                'year' => 2026,
                'budget_year' => 2027,
                'budget_minimum_step' => 5,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('movement_workbooks', [
            'id' => $response->json('data.id'),
            'mda_id' => $mdaA->id,
            'name' => '2027 Full Details',
            'year' => 2026,
            'budget_year' => 2027,
            'budget_minimum_step' => 5,
        ]);

        $this->actingAs($user)
            ->getJson('/api/movement-workbooks/'.$response->json('data.id'))
            ->assertOk()
            ->assertJsonPath('data.name', '2027 Full Details')
            ->assertJsonPath('data.budget_year', 2027)
            ->assertJsonPath('data.budget_minimum_step', 5)
            ->assertJsonStructure(['data' => ['lines', 'summaries', 'department_summaries']]);

        $this->actingAs($user)
            ->get('/api/movement-workbooks/'.$response->json('data.id').'/summary-export')
            ->assertOk()
            ->assertDownload('2027-full-details-summary.xlsx');

        $this->actingAs($user)
            ->get('/api/movement-workbooks/'.$response->json('data.id').'/detail-export')
            ->assertOk()
            ->assertDownload('2027-full-details-detail.xlsx');
    }

    protected function createMdas(): array
    {
        $mdaA = Mda::query()->create([
            'code' => 'MOH',
            'name' => 'MINISTRY OF HEALTH',
            'status' => 'active',
        ]);

        Department::query()->create([
            'mda_id' => $mdaA->id,
            'code' => 'ADMIN',
            'name' => 'ADMIN',
            'status' => 'active',
        ]);

        $mdaB = Mda::query()->create([
            'code' => 'HMB',
            'name' => 'HOSPITAL MANAGEMENT BOARD',
            'status' => 'active',
        ]);

        Department::query()->create([
            'mda_id' => $mdaB->id,
            'code' => 'ADMIN',
            'name' => 'ADMIN',
            'status' => 'active',
        ]);

        return [$mdaA, $mdaB];
    }

    protected function createSalaryScale(): SalaryScale
    {
        return SalaryScale::query()->create([
            'code' => 'GL',
            'name' => 'GRADE LEVEL',
            'min_level' => 1,
            'max_level' => 17,
            'min_step' => 1,
            'max_step' => 15,
            'status' => 'active',
        ]);
    }

}
