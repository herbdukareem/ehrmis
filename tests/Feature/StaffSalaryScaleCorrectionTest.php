<?php

namespace Tests\Feature;

use App\Domain\Legacy\Models\LegacyStaffImportBatch;
use App\Domain\Legacy\Models\LegacyStaffImportRow;
use App\Domain\Movement\Models\MovementWorkbook;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Mda;
use App\Domain\Staff\Models\AllowanceType;
use App\Domain\Staff\Models\SalaryScale;
use App\Domain\Staff\Models\SalaryStructureRate;
use App\Domain\Staff\Models\Staff;
use App\Domain\Staff\Services\StaffSalaryScaleCorrectionService;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class StaffSalaryScaleCorrectionTest extends TestCase
{
    use RefreshDatabase;

    protected Mda $mda;

    protected User $actor;

    protected SalaryScale $gl;

    protected SalaryScale $ch;

    protected StaffSalaryScaleCorrectionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 9, 12)->startOfDay());
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->mda = Mda::factory()->create(['code' => 'HMB']);
        $this->actor = User::factory()->superAdmin()->create();
        $this->actor->assignRole('Super Admin');
        $this->actingAs($this->actor);
        $this->gl = SalaryScale::query()->firstOrCreate(['code' => 'GL'], ['name' => 'Grade level', 'status' => 'active']);
        $this->ch = SalaryScale::query()->firstOrCreate(['code' => 'CH'], ['name' => 'CONHESS', 'status' => 'active']);
        $this->service = app(StaffSalaryScaleCorrectionService::class);
    }

    public function test_correction_uses_workbook_scale_recalculates_money_and_preserves_approved_snapshots_and_other_mdas(): void
    {
        $person = $this->person('C10826');
        $old = $person->currentSalaryPlacement;
        $employment = $person->currentEmployment->getAttributes();
        $personBefore = $person->fresh()->getAttributes();
        $foreign = $this->person('FOREIGN', Mda::factory()->create()->id);
        $foreignBefore = $foreign->currentSalaryPlacement->getAttributes();
        $rate = $this->rate(8, 6);
        $hazard = AllowanceType::query()->create(['mda_id' => $this->mda->id, 'code' => 'hazard', 'name' => 'Hazard', 'status' => 'active']);
        $rate->rateAllowances()->create(['allowance_type_id' => $hazard->id, 'amount' => 10000, 'status' => 'active']);
        $person->allowanceAssignments()->create(['allowance_type_id' => $hazard->id, 'is_eligible' => true]);
        $assignmentsBefore = $person->allowanceAssignments()->get()->toArray();
        $book = MovementWorkbook::query()->create(['mda_id' => $this->mda->id, 'name' => 'Approved movement', 'year' => 2026, 'status' => 'approved']);
        $line = $book->lines()->create(['staff_id' => $person->id, 'current_salary_placement_id' => $old->id, 'current_salary_scale_id' => $this->gl->id, 'current_level' => 8, 'current_step' => 6, 'current_amounts' => ['calculated_gross' => 55000], 'eligibility_status' => 'due', 'retirement_status' => 'active']);
        $snapshots = [$book->fresh()->getAttributes(), $line->fresh()->getAttributes()];
        $batch = LegacyStaffImportBatch::query()->create(['source_database' => 'upload', 'source_table' => 'staff']);
        LegacyStaffImportRow::query()->create(['batch_id' => $batch->id, 'mda_id' => $this->mda->id, 'published_staff_id' => $person->id, 'raw_payload' => ['source_row' => ['salary_scale' => 'GL', 'cadre' => 'Store Officers']]]);

        $preview = $this->service->correct($this->mda, $this->actor, [$this->row($person, 'CH8/6')], []);
        $this->assertSame(1, $preview['updated']);
        $this->assertSame($old->id, $person->fresh()->currentSalaryPlacement->id);
        $this->assertDatabaseCount('audit_logs', 0);
        $backup = null;
        $result = $this->service->correct($this->mda, $this->actor, [$this->row($person, 'CH8/6')], ['filename' => 'source.xlsx'], false, function ($report) use (&$backup, $person, $old) {
            $this->assertSame($old->id, $person->fresh()->currentSalaryPlacement->id);
            $backup = $report;
        });
        $current = $person->fresh()->currentSalaryPlacement;
        $this->assertSame($this->ch->id, $current->salary_scale_id);
        $this->assertSame('80000.00', $current->basic_salary);
        $this->assertSame('90000.00', $current->gross_salary);
        $this->assertSame(['hazard' => 10000], $current->allowance_breakdown_snapshot);
        $this->assertFalse($old->fresh()->is_current);
        $this->assertSame('2026-09-12', $old->fresh()->effective_to->toDateString());
        $this->assertSame('55000.00', $old->fresh()->gross_salary);
        $this->assertSame($old->id, $backup['updates'][0]['old_placement_id']);
        $this->assertSame($snapshots, [$book->fresh()->getAttributes(), $line->fresh()->getAttributes()]);
        $this->assertSame($employment, $person->fresh()->currentEmployment->getAttributes());
        $this->assertSame($personBefore, $person->fresh()->getAttributes());
        $this->assertSame($foreignBefore, $foreign->fresh()->currentSalaryPlacement->getAttributes());
        $this->assertSame($assignmentsBefore, $person->allowanceAssignments()->get()->toArray());
        $this->assertStringContainsString('saved upload', $result['updates'][0]['reason']);
        $this->assertDatabaseHas('audit_logs', ['event_code' => 'staff.salary_scale_corrected_from_workbook', 'actor_user_id' => $this->actor->id, 'auditable_id' => $person->id]);
        $repeat = $this->service->correct($this->mda, $this->actor, [$this->row($person, 'CH8/6')], [], false);
        $this->assertSame(0, $repeat['updated']);
        $this->assertSame(1, $repeat['verified_count']);
        $this->assertSame(2, $person->salaryPlacements()->count());
    }

    public function test_conflicting_duplicates_missing_rates_and_invalid_placements_are_reported_without_guessing(): void
    {
        $conflict = $this->person('DUP');
        $missing = $this->person('MISSING');
        $invalid = $this->person('INVALID');
        $this->rate(8, 6);
        $result = $this->service->correct($this->mda, $this->actor, [
            $this->row($conflict, 'CH8/6'), $this->row($conflict, 'CH8/7', 3),
            $this->row($missing, 'CH13/12'), $this->row($invalid, '=CH8/6'),
        ], [], false);
        $this->assertSame(0, $result['updated']);
        $this->assertCount(3, $result['issues']);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertSame($this->gl->id, $conflict->fresh()->currentSalaryPlacement->salary_scale_id);
    }

    public function test_equivalent_source_formatting_and_consistent_duplicates_do_not_create_false_corrections(): void
    {
        $person = $this->person('FORMAT');
        $result = $this->service->correct($this->mda, $this->actor, [
            $this->row($person, ' gl 08/06` '), $this->row($person, 'GRADE LEVEL 8/6', 3),
        ], [], false);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(1, $result['verified_count']);
        $this->assertSame([], $result['issues']);
    }

    public function test_wrong_mda_and_ambiguous_identity_are_never_corrected(): void
    {
        $person = $this->person('LOCAL');
        $row = $this->row($person, 'CH8/6');
        $row['name'] = 'Unknown Person';
        $result = $this->service->correct($this->mda, $this->actor, [$row], [], false);
        $this->assertSame(0, $result['updated']);
        $this->assertCount(1, $result['unmatched_rows']);
        $row = $this->row($person, 'CH8/6');
        $row['mda'] = 'MOH';
        $this->expectException(InvalidArgumentException::class);
        $this->service->correct($this->mda, $this->actor, [$row], [], false);
    }

    public function test_mda_limited_actor_cannot_run_bulk_salary_corrections(): void
    {
        $actor = User::factory()->mdaUser($this->mda)->create();
        $actor->assignRole('MDA Admin');
        $this->actingAs($actor);
        $this->expectException(HttpException::class);
        $this->service->correct($this->mda, $actor, [], [], false);
    }

    public function test_failed_backup_prevents_salary_changes(): void
    {
        $person = $this->person('BACKUP');
        $old = $person->currentSalaryPlacement->getAttributes();
        $this->rate(8, 6);
        try {
            $this->service->correct($this->mda, $this->actor, [$this->row($person, 'CH8/6')], [], false, fn () => throw new RuntimeException('Backup failed'));
            $this->fail('Expected failed backup to abort.');
        } catch (RuntimeException $e) {
            $this->assertSame('Backup failed', $e->getMessage());
        }
        $this->assertSame($old, $person->fresh()->currentSalaryPlacement->getAttributes());
        $this->assertDatabaseCount('audit_logs', 0);
    }

    protected function person(string $number, ?int $mdaId = null): Staff
    {
        $mdaId ??= $this->mda->id;
        $person = Staff::query()->create(['mda_id' => $mdaId, 'staff_number' => $number, 'legacy_cno' => $number, 'full_name' => 'Person '.$number, 'surname' => 'Person', 'first_name' => $number, 'status' => 'active']);
        $department = Department::factory()->create(['mda_id' => $mdaId]);
        $person->employments()->create(['mda_id' => $mdaId, 'department_id' => $department->id, 'is_current' => true, 'date_last_promotion' => '2023-07-01', 'effective_from' => '2023-07-01']);
        $person->salaryPlacements()->create(['salary_scale_id' => $this->gl->id, 'level' => 8, 'step' => 6, 'basic_salary' => 50000, 'gross_salary' => 55000, 'is_current' => true, 'effective_from' => '2023-07-01']);

        return $person;
    }

    protected function row(Staff $person, string $placement, int $number = 2): array
    {
        return ['row' => $number, 'mda' => 'HMB', 'name' => $person->full_name, 'cno' => $person->staff_number, 'level_step' => $placement];
    }

    protected function rate(int $level, int $step): SalaryStructureRate
    {
        return SalaryStructureRate::query()->updateOrCreate(['salary_scale_id' => $this->ch->id, 'level' => $level, 'step' => $step], ['basic_salary' => 80000, 'status' => 'active']);
    }
}
