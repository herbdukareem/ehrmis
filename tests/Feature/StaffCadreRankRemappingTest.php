<?php

namespace Tests\Feature;

use App\Domain\Movement\Models\MovementWorkbook;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Mda;
use App\Domain\Staff\Models\Cadre;
use App\Domain\Staff\Models\Rank;
use App\Domain\Staff\Models\SalaryScale;
use App\Domain\Staff\Models\Staff;
use App\Domain\Staff\Services\StaffCadreRankRemappingService;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class StaffCadreRankRemappingTest extends TestCase
{
    use RefreshDatabase;

    protected Mda $mda;

    protected Department $department;

    protected User $actor;

    protected SalaryScale $scale;

    protected StaffCadreRankRemappingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 9, 12)->startOfDay());
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->actor = User::factory()->superAdmin()->create();
        $this->actor->assignRole('Super Admin');
        $this->actingAs($this->actor);
        $this->mda = Mda::factory()->create(['code' => 'HMB']);
        $this->department = Department::factory()->create(['mda_id' => $this->mda->id]);
        $this->scale = SalaryScale::query()->firstOrCreate(['code' => 'CH'], ['name' => 'CONHESS']);
        $this->service = app(StaffCadreRankRemappingService::class);
    }

    public function test_explicit_rank_at_current_grade_preserves_other_catalogue_entries_salary_and_approved_history(): void
    {
        $person = $this->person('C10826');
        $old = $person->currentEmployment;
        $salaryBefore = $person->currentSalaryPlacement->getAttributes();
        $cadre = Cadre::query()->create(['department_id' => $this->department->id, 'salary_scale_id' => $this->scale->id, 'name' => 'Statistical Officer', 'status' => 'active']);
        $existingRank = Rank::query()->create(['cadre_id' => $cadre->id, 'salary_scale_id' => $this->scale->id, 'name' => 'H.S.O', 'level' => 7, 'status' => 'active']);
        $rankBefore = $existingRank->fresh()->getAttributes();
        $book = MovementWorkbook::query()->create(['mda_id' => $this->mda->id, 'name' => 'Approved', 'year' => 2026, 'status' => 'approved']);
        $line = $book->lines()->create(['staff_id' => $person->id, 'current_employment_id' => $old->id, 'current_salary_scale_id' => $this->scale->id, 'current_level' => 8, 'current_step' => 6, 'eligibility_status' => 'due', 'retirement_status' => 'active']);
        $snapshots = [$book->fresh()->getAttributes(), $line->fresh()->getAttributes()];
        $mapping = $this->mapping($person);
        $preview = $this->service->remap($this->mda, $this->actor, [$mapping], []);
        $this->assertSame(1, $preview['updated']);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertSame($old->id, $person->fresh()->currentEmployment->id);
        $result = $this->service->remap($this->mda, $this->actor, [$mapping], [], false);
        $current = $person->fresh()->currentEmployment;
        $this->assertSame($cadre->id, $current->cadre_id);
        $this->assertSame('HSO', $current->rank->name);
        $this->assertSame(8, (int) $current->rank->level);
        $this->assertSame($salaryBefore, $person->fresh()->currentSalaryPlacement->getAttributes());
        $this->assertSame($rankBefore, $existingRank->fresh()->getAttributes());
        $this->assertSame('2023-01-01', $current->date_last_promotion->toDateString());
        $this->assertSame('2026-01-01', $current->next_promotion_date->toDateString());
        $this->assertSame($snapshots, [$book->fresh()->getAttributes(), $line->fresh()->getAttributes()]);
        $this->assertFalse($old->fresh()->is_current);
        $this->assertCount(1, $result['created_ranks']);
        $this->assertCount(0, $result['created_cadres']);
        $this->assertDatabaseHas('audit_logs', ['event_code' => 'staff.cadre_rank_remapped', 'actor_user_id' => $this->actor->id, 'auditable_id' => $person->id]);
        $repeat = $this->service->remap($this->mda, $this->actor, [$mapping], [], false);
        $this->assertSame(0, $repeat['updated']);
        $this->assertCount(1, $repeat['unchanged']);
        $this->assertSame(2, $person->employments()->count());
    }

    public function test_new_local_catalogue_records_are_shared_only_within_the_same_department_scale_and_grade(): void
    {
        $foreignDepartment = Department::factory()->create();
        $foreignCadre = Cadre::query()->create(['department_id' => $foreignDepartment->id, 'salary_scale_id' => $this->scale->id, 'name' => 'Statistical Officer', 'status' => 'active']);
        $foreignRank = Rank::query()->create(['cadre_id' => $foreignCadre->id, 'salary_scale_id' => $this->scale->id, 'name' => 'HSO', 'level' => 8, 'status' => 'active']);
        $first = $this->person('FIRST');
        $second = $this->person('SECOND');
        $result = $this->service->remap($this->mda, $this->actor, [$this->mapping($first), $this->mapping($second)], [], false);
        $this->assertCount(1, $result['created_cadres']);
        $this->assertCount(1, $result['created_ranks']);
        $this->assertSame($first->fresh()->currentEmployment->rank_id, $second->fresh()->currentEmployment->rank_id);
        $this->assertNotSame($foreignRank->id, $first->fresh()->currentEmployment->rank_id);
        $this->assertSame($this->department->id, $first->fresh()->currentEmployment->cadre->department_id);
    }

    public function test_stale_salary_and_conflicting_staff_mappings_abort_without_partial_updates(): void
    {
        $first = $this->person('FIRST');
        $second = $this->person('SECOND');
        $stale = $this->mapping($second);
        $stale['level'] = 9;
        try {
            $this->service->remap($this->mda, $this->actor, [$this->mapping($first), $stale], [], false);
            $this->fail('Expected stale salary rejection.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Salary placement changed', $e->getMessage());
        }
        $this->assertSame(1, $first->employments()->count());
        $this->assertDatabaseCount('audit_logs', 0);
        $this->expectException(InvalidArgumentException::class);
        $this->service->remap($this->mda, $this->actor, [$this->mapping($first), $this->mapping($first)], [], false);
    }

    public function test_failed_backup_prevents_catalogue_and_appointment_mutations(): void
    {
        $person = $this->person('BACKUP');
        $cadres = Cadre::query()->count();
        try {
            $this->service->remap($this->mda, $this->actor, [$this->mapping($person)], [], false, fn () => throw new RuntimeException('Backup failed'));
            $this->fail('Expected backup failure.');
        } catch (RuntimeException $e) {
            $this->assertSame('Backup failed', $e->getMessage());
        }
        $this->assertSame($cadres, Cadre::query()->count());
        $this->assertSame(1, $person->employments()->count());
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_mda_limited_actor_cannot_remap_staff(): void
    {
        $actor = User::factory()->mdaUser($this->mda)->create();
        $actor->assignRole('MDA Admin');
        $this->actingAs($actor);
        $this->expectException(HttpException::class);
        $this->service->remap($this->mda, $actor, [], [], false);
    }

    protected function person(string $number): Staff
    {
        $person = Staff::query()->create(['mda_id' => $this->mda->id, 'staff_number' => $number, 'full_name' => 'Person '.$number, 'surname' => 'Person', 'first_name' => $number, 'status' => 'active']);
        $person->employments()->create(['mda_id' => $this->mda->id, 'department_id' => $this->department->id, 'is_current' => true, 'date_last_promotion' => '2023-01-01', 'next_promotion_date' => '2026-01-01', 'effective_from' => '2023-01-01']);
        $person->salaryPlacements()->create(['salary_scale_id' => $this->scale->id, 'level' => 8, 'step' => 6, 'basic_salary' => 80000, 'gross_salary' => 85000, 'is_current' => true]);

        return $person;
    }

    protected function mapping(Staff $person): array
    {
        return ['staff_number' => $person->staff_number, 'name' => $person->full_name, 'cadre' => 'Statistical Officer', 'rank' => 'HSO', 'salary_scale' => 'CH', 'level' => 8, 'step' => 6];
    }
}
