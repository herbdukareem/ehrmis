<?php

namespace Tests\Feature;

use App\Domain\Budget\Models\BudgetWorkbook;
use App\Domain\Movement\Models\MovementLine;
use App\Domain\Movement\Models\MovementSummary;
use App\Domain\Movement\Models\MovementWorkbook;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Mda;
use App\Domain\Staff\Models\SalaryScale;
use App\Domain\Staff\Models\Staff;
use App\Domain\Staff\Models\StaffEmployment;
use App\Domain\Staff\Models\StaffSalaryPlacement;
use App\Models\User;
use App\Models\UserAccessScope;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\IOFactory;
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
            ->assertAccepted();

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

    public function test_multi_mda_budget_officer_can_use_only_assigned_mdas(): void
    {
        [$mdaA, $mdaB] = $this->createMdas();
        $mdaC = Mda::query()->create([
            'code' => 'EDU',
            'name' => 'MINISTRY OF EDUCATION',
            'status' => 'active',
        ]);

        Department::query()->create([
            'mda_id' => $mdaC->id,
            'code' => 'ADMIN',
            'name' => 'ADMIN',
            'status' => 'active',
        ]);

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
        $movementC = MovementWorkbook::query()->create([
            'mda_id' => $mdaC->id,
            'year' => 2026,
            'status' => 'approved',
        ]);

        $user = User::factory()->mdaUser($mdaA)->create();
        $user->assignRole('Budget Officer');
        UserAccessScope::query()->create([
            'user_id' => $user->id,
            'scope_type' => 'mda',
            'mda_id' => $mdaB->id,
        ]);

        $this->actingAs($user)
            ->getJson('/api/movement-workbooks')
            ->assertOk()
            ->assertJsonFragment(['id' => $movementA->id, 'year' => 2026])
            ->assertJsonFragment(['id' => $movementB->id, 'year' => 2026])
            ->assertJsonMissing(['id' => $movementC->id]);

        $this->actingAs($user)
            ->postJson('/api/movement-workbooks', [
                'mda_id' => $mdaB->id,
                'name' => 'HMB Secondary Workbook',
                'year' => 2027,
                'budget_year' => 2028,
                'budget_minimum_step' => 5,
            ])
            ->assertAccepted();

        $this->actingAs($user)
            ->postJson('/api/budget-workbooks', [
                'movement_workbook_id' => $movementB->id,
            ])
            ->assertCreated();

        $this->actingAs($user)
            ->postJson('/api/movement-workbooks', [
                'mda_id' => $mdaC->id,
                'name' => 'Blocked Workbook',
                'year' => 2027,
                'budget_year' => 2028,
                'budget_minimum_step' => 5,
            ])
            ->assertForbidden();

        $this->actingAs($user)
            ->postJson('/api/budget-workbooks', [
                'movement_workbook_id' => $movementC->id,
            ])
            ->assertForbidden();
    }

    public function test_department_scoped_user_only_sees_assigned_department_movement_rows(): void
    {
        [$mda] = $this->createMdas();
        $scale = $this->createSalaryScale();
        $departmentA = Department::query()->where('mda_id', $mda->id)->where('code', 'ADMIN')->firstOrFail();
        $departmentB = Department::query()->create([
            'mda_id' => $mda->id,
            'code' => 'FIN',
            'name' => 'FINANCE',
            'status' => 'active',
        ]);

        $workbook = MovementWorkbook::query()->create([
            'mda_id' => $mda->id,
            'name' => '2027 Movement',
            'year' => 2026,
            'budget_year' => 2027,
            'budget_minimum_step' => 5,
            'status' => 'draft',
        ]);

        $lineA = $this->createMovementLine($workbook, $mda, $departmentA, $scale, 'STF-A', 'Visible Staff');
        $lineB = $this->createMovementLine($workbook, $mda, $departmentB, $scale, 'STF-B', 'Hidden Staff');

        foreach ([[$departmentA, 1], [$departmentB, 2]] as [$department, $count]) {
            MovementSummary::query()->create([
                'workbook_id' => $workbook->id,
                'department_id' => $department->id,
                'salary_scale_id' => $scale->id,
                'level' => 9,
                'staff_count' => $count,
                'due_count' => 0,
                'retiring_count' => 0,
                'retired_count' => 0,
                'blocked_count' => 0,
                'current_gross_total' => 50000 * $count,
                'proposed_gross_total' => 50000 * $count,
                'variance_total' => 0,
            ]);
        }

        $user = User::factory()->mdaUser($mda)->create();
        $user->givePermissionTo('view-movement-sheets', 'create-movement-sheets');
        UserAccessScope::query()->create([
            'user_id' => $user->id,
            'scope_type' => 'department',
            'mda_id' => $mda->id,
            'department_id' => $departmentA->id,
        ]);

        $index = $this->actingAs($user)
            ->getJson('/api/movement-workbooks')
            ->assertOk();
        $this->assertSame(1, $index->json('data.0.line_count'));

        $detail = $this->actingAs($user)
            ->getJson('/api/movement-workbooks/'.$workbook->id)
            ->assertOk();

        $this->assertSame([$lineA->id], array_column($detail->json('data.lines'), 'id'));
        $this->assertSame([$departmentA->id], array_column($detail->json('data.department_summaries'), 'department_id'));
        $this->assertSame([$departmentA->name], array_values(array_unique(array_column($detail->json('data.summaries'), 'department'))));
        $this->assertStringNotContainsString('Hidden Staff', json_encode($detail->json('data')));

        $this->actingAs($user)
            ->patchJson("/api/movement-workbooks/{$workbook->id}/lines/{$lineB->id}/flags", [
                'is_contract_staff' => false,
                'is_special_movement' => true,
                'proposed_level' => 10,
            ])
            ->assertForbidden();

        $this->actingAs($user)
            ->get("/api/movement-workbooks/{$workbook->id}/summary-export?department_id={$departmentB->id}")
            ->assertForbidden();

        $summaryResponse = $this->actingAs($user)
            ->get("/api/movement-workbooks/{$workbook->id}/summary-export")
            ->assertOk()
            ->assertDownload('2027-movement-summary.xlsx');
        $summaryPath = $summaryResponse->baseResponse->getFile()->getPathname();
        try {
            Cell::setValueBinder(new DefaultValueBinder);
            $book = IOFactory::load($summaryPath);
            $this->assertSame([$departmentA->name], $book->getSheetNames());
            $this->assertStringNotContainsString($departmentB->name, json_encode($book->getActiveSheet()->toArray()));
            $book->disconnectWorksheets();
        } finally {
            unlink($summaryPath);
        }

        $detailResponse = $this->actingAs($user)
            ->get("/api/movement-workbooks/{$workbook->id}/detail-export")
            ->assertOk()
            ->assertDownload('2027-movement-detail.xlsx');
        $detailPath = $detailResponse->baseResponse->getFile()->getPathname();
        try {
            Cell::setValueBinder(new DefaultValueBinder);
            $book = IOFactory::load($detailPath);
            $payload = json_encode($book->getActiveSheet()->toArray());
            $this->assertStringContainsString('Visible Staff', $payload);
            $this->assertStringNotContainsString('Hidden Staff', $payload);
            $this->assertStringNotContainsString($departmentB->name, $payload);
            $book->disconnectWorksheets();
        } finally {
            unlink($detailPath);
        }

        $admin = User::factory()->mdaUser($mda)->create();
        $admin->givePermissionTo('view-movement-sheets');

        $adminIndex = $this->actingAs($admin)
            ->getJson('/api/movement-workbooks')
            ->assertOk();
        $this->assertSame(2, $adminIndex->json('data.0.line_count'));

        $adminDetail = $this->actingAs($admin)
            ->getJson('/api/movement-workbooks/'.$workbook->id)
            ->assertOk();
        $this->assertEqualsCanonicalizing(
            [$lineA->id, $lineB->id],
            array_column($adminDetail->json('data.lines'), 'id')
        );
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
        return SalaryScale::query()->firstOrCreate(
            ['code' => 'GL'],
            [
                'name' => 'GRADE LEVEL',
                'min_level' => 1,
                'max_level' => 17,
                'min_step' => 1,
                'max_step' => 15,
                'status' => 'active',
            ],
        );
    }

    protected function createMovementLine(
        MovementWorkbook $workbook,
        Mda $mda,
        Department $department,
        SalaryScale $scale,
        string $staffNumber,
        string $fullName,
    ): MovementLine {
        $staff = Staff::withoutGlobalScopes()->create([
            'mda_id' => $mda->id,
            'staff_number' => $staffNumber,
            'surname' => explode(' ', $fullName)[0],
            'first_name' => explode(' ', $fullName)[1] ?? 'User',
            'full_name' => $fullName,
            'status' => 'active',
        ]);

        $employment = StaffEmployment::query()->create([
            'staff_id' => $staff->id,
            'mda_id' => $mda->id,
            'department_id' => $department->id,
            'employment_status' => 'active',
            'is_current' => true,
            'effective_from' => '2024-01-01',
        ]);

        $placement = StaffSalaryPlacement::query()->create([
            'staff_id' => $staff->id,
            'salary_scale_id' => $scale->id,
            'level' => 9,
            'step' => 5,
            'gross_salary' => 50000,
            'is_current' => true,
            'effective_from' => '2024-01-01',
        ]);

        return MovementLine::query()->create([
            'workbook_id' => $workbook->id,
            'staff_id' => $staff->id,
            'current_employment_id' => $employment->id,
            'current_salary_placement_id' => $placement->id,
            'current_salary_scale_id' => $scale->id,
            'proposed_salary_scale_id' => $scale->id,
            'selection_state' => 'included',
            'eligibility_status' => 'not_due',
            'retirement_status' => 'active',
            'current_level' => 9,
            'current_step' => 5,
            'proposed_level' => 9,
            'proposed_step' => 5,
            'current_amounts' => ['calculated_gross' => 50000],
            'proposed_amounts' => ['calculated_gross' => 50000],
        ]);
    }

}
