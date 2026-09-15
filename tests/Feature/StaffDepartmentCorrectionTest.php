<?php

namespace Tests\Feature;

use App\Domain\Budget\Models\BudgetWorkbook;
use App\Domain\Budget\Services\BudgetReportService;
use App\Domain\Legacy\Models\LegacyStaffImportBatch;
use App\Domain\Legacy\Models\LegacyStaffImportRow;
use App\Domain\Movement\Models\MovementWorkbook;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Mda;
use App\Domain\Staff\Models\SalaryScale;
use App\Domain\Staff\Models\Staff;
use App\Domain\Staff\Services\StaffDepartmentCorrectionService;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class StaffDepartmentCorrectionTest extends TestCase
{
    use RefreshDatabase;

    protected Mda $mda;

    protected Department $admin;

    protected Department $nursing;

    protected User $actor;

    protected StaffDepartmentCorrectionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 9, 12)->startOfDay());
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->mda = Mda::factory()->create(['code' => 'HMB']);
        $this->admin = Department::factory()->create(['mda_id' => $this->mda->id, 'code' => 'ADMIN', 'name' => 'Administration']);
        $this->nursing = Department::factory()->create(['mda_id' => $this->mda->id, 'code' => 'NURSING', 'name' => 'Nursing']);
        $this->actor = User::factory()->superAdmin()->create();
        $this->actor->assignRole('Super Admin');
        $this->actingAs($this->actor);
        $this->service = app(StaffDepartmentCorrectionService::class);
    }

    public function test_department_correction_preserves_approved_workbook_snapshots_salary_and_other_staff_fields(): void
    {
        $person = $this->person('C001');
        $original = $person->currentEmployment;
        $person->personalDetail()->create(['file_no' => 'G001', 'lga' => 'LAPAI']);
        $scale = SalaryScale::query()->create(['code' => 'TEST', 'name' => 'Test scale', 'status' => 'active']);
        $placement = $person->salaryPlacements()->create(['salary_scale_id' => $scale->id, 'level' => 8, 'step' => 6, 'basic_salary' => 100000, 'gross_salary' => 105000, 'is_current' => true]);
        $movement = MovementWorkbook::query()->create(['mda_id' => $this->mda->id, 'name' => 'Approved movement', 'year' => 2026, 'budget_year' => 2027, 'budget_minimum_step' => 6, 'status' => 'approved']);
        $line = $movement->lines()->create(['staff_id' => $person->id, 'current_employment_id' => $original->id, 'current_salary_scale_id' => $scale->id, 'current_level' => 8, 'current_step' => 6, 'eligibility_status' => 'due', 'retirement_status' => 'active', 'current_amounts' => ['calculated_gross' => 105000]]);
        $budget = BudgetWorkbook::query()->create(['mda_id' => $this->mda->id, 'movement_workbook_id' => $movement->id, 'year' => 2026, 'status' => 'approved']);
        $budgetLine = $budget->lines()->create(['department_id' => $this->admin->id, 'salary_scale_id' => $scale->id, 'level' => 8, 'staff_count' => 1, 'current_gross_total' => 105000, 'proposed_gross_total' => 110000, 'variance_total' => 5000]);
        $reportBefore = app(BudgetReportService::class)->build($budget, 'staff-list');
        $saved = [$movement->fresh()->getAttributes(), $line->fresh()->getAttributes(), $budget->fresh()->getAttributes(), $budgetLine->fresh()->getAttributes(), $placement->fresh()->getAttributes(), $person->fresh()->getAttributes()];
        $foreign = $this->person('FOREIGN', Mda::factory()->create()->id);
        $rows = [$this->row($person, 'nursing')];
        $preview = $this->service->correct($this->mda, $this->actor, $rows, [], true);
        $this->assertSame(1, $preview['updated']);
        $this->assertSame(1, $preview['affected_movement_workbooks'][0]['affected_staff']);
        $this->assertSame($budget->id, $preview['affected_budget_workbooks'][0]['id']);
        $this->assertSame($original->id, $person->fresh()->currentEmployment->id);
        $this->assertDatabaseCount('audit_logs', 0);
        $applied = $this->service->correct($this->mda, $this->actor, $rows, ['filename' => 'staff.xlsx']);
        $this->assertSame(1, $applied['updated']);
        $current = $person->fresh()->currentEmployment;
        $this->assertNotSame($original->id, $current->id);
        $this->assertSame($this->nursing->id, $current->department_id);
        $this->assertSame('2010-01-01', $current->date_first_appointment->format('Y-m-d'));
        $this->assertSame('2024-01-01', $current->date_last_promotion->format('Y-m-d'));
        $this->assertSame('2026-09-12', $current->effective_from->format('Y-m-d'));
        $this->assertFalse($original->fresh()->is_current);
        $this->assertSame($this->admin->id, $line->fresh()->currentEmployment->department_id);
        $this->assertEquals($reportBefore, app(BudgetReportService::class)->build($budget->fresh(), 'staff-list'));
        $this->assertSame($saved, [$movement->fresh()->getAttributes(), $line->fresh()->getAttributes(), $budget->fresh()->getAttributes(), $budgetLine->fresh()->getAttributes(), $placement->fresh()->getAttributes(), $person->fresh()->getAttributes()]);
        $this->assertSame('G001', $person->fresh()->personalDetail->file_no);
        $this->assertSame('LAPAI', $person->fresh()->personalDetail->lga);
        $this->assertSame($foreign->mda_id, $foreign->fresh()->currentEmployment->mda_id);
        $this->assertDatabaseHas('audit_logs', ['event_code' => 'staff.department_corrected_from_workbook', 'actor_user_id' => $this->actor->id, 'auditable_id' => $person->id]);
        $repeat = $this->service->correct($this->mda, $this->actor, $rows, []);
        $this->assertSame(0, $repeat['updated']);
        $this->assertSame(2, $person->employments()->count());
    }

    public function test_historical_identity_matching_and_hmb_department_alias_keep_the_current_staff_identity(): void
    {
        $person = $this->person('NEW-CNO');
        $him = Department::factory()->create(['mda_id' => $this->mda->id, 'code' => 'HIM', 'name' => 'Health Information Management']);
        $batch = LegacyStaffImportBatch::query()->create(['source_database' => 'upload', 'source_table' => 'staff']);
        LegacyStaffImportRow::query()->create(['batch_id' => $batch->id, 'mda_id' => $this->mda->id, 'published_staff_id' => $person->id, 'raw_payload' => ['source_row' => ['name' => 'Original Name', 'cno' => 'OLD-CNO']]]);
        $row = $this->row($person, 'PRS/HIM', ['name' => 'Original Name', 'cno' => 'OLD-CNO']);
        $result = $this->service->correct($this->mda, $this->actor, [$row, array_replace($row, ['row' => 3])], []);
        $this->assertSame(1, $result['updated']);
        $this->assertSame($him->id, $person->fresh()->currentEmployment->department_id);
        $this->assertSame('NEW-CNO', $person->fresh()->staff_number);
        $this->assertSame('Person NEW-CNO', $person->fresh()->full_name);
    }

    public function test_conflicting_duplicates_unknown_departments_and_ambiguous_staff_are_not_updated(): void
    {
        $conflict = $this->person('CONFLICT');
        $unknown = $this->person('UNKNOWN');
        $first = $this->person('ONE');
        $second = $this->person('TWO');
        foreach ([$first, $second] as $person) {
            $person->update(['legacy_cno' => 'DUPLICATE', 'full_name' => 'Same Name']);
        }
        $result = $this->service->correct($this->mda, $this->actor, [
            $this->row($conflict, 'ADMIN'), $this->row($conflict, 'NURSING', ['row' => 3]),
            $this->row($unknown, 'UNKNOWN DEPARTMENT'),
            $this->row($first, 'NURSING', ['name' => 'Same Name', 'cno' => 'DUPLICATE']),
        ], []);
        $this->assertSame(0, $result['updated']);
        $this->assertCount(2, $result['issues']);
        $this->assertCount(1, $result['unmatched_rows']);
        $this->assertSame($this->admin->id, $conflict->fresh()->currentEmployment->department_id);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_foreign_mda_rows_are_rejected_and_foreign_department_names_cannot_be_used(): void
    {
        $person = $this->person('LOCAL');
        Department::factory()->create(['code' => 'FOREIGN', 'name' => 'Foreign department']);
        $result = $this->service->correct($this->mda, $this->actor, [$this->row($person, 'FOREIGN')], []);
        $this->assertSame(0, $result['updated']);
        $this->assertCount(1, $result['issues']);
        $this->expectException(InvalidArgumentException::class);
        $this->service->correct($this->mda, $this->actor, [$this->row($person, 'NURSING', ['mda' => 'OTHER'])], []);
    }

    public function test_bulk_department_correction_rejects_an_mda_limited_actor(): void
    {
        $actor = User::factory()->mdaUser($this->mda)->create();
        $actor->assignRole('MDA Admin');
        $this->expectException(HttpException::class);
        $this->service->correct($this->mda, $actor, [], []);
    }

    protected function person(string $number, ?int $mdaId = null): Staff
    {
        $mdaId ??= $this->mda->id;
        $person = Staff::query()->create(['mda_id' => $mdaId, 'staff_number' => $number, 'legacy_cno' => $number, 'full_name' => 'Person '.$number, 'surname' => 'Person', 'first_name' => $number, 'date_of_birth' => '1980-01-01', 'status' => 'active']);
        $departmentId = $mdaId === $this->mda->id ? $this->admin->id : Department::factory()->create(['mda_id' => $mdaId])->id;
        $person->employments()->create(['mda_id' => $mdaId, 'department_id' => $departmentId, 'is_current' => true, 'employment_status' => 'active', 'date_first_appointment' => '2010-01-01', 'date_last_promotion' => '2024-01-01', 'effective_from' => '2024-01-01']);

        return $person;
    }

    protected function row(Staff $person, string $department, array $overrides = []): array
    {
        return array_replace(['row' => 2, 'mda' => 'HMB', 'name' => $person->full_name, 'cno' => $person->legacy_cno, 'dob' => '1980-01-01', 'department' => $department], $overrides);
    }
}
