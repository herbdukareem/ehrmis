<?php

namespace Tests\Feature;

use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Mda;
use App\Domain\Organization\Models\Station;
use App\Domain\Staff\Models\Staff;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardRetirementStaffTest extends TestCase
{
    use RefreshDatabase;

    protected Mda $mda;

    protected Department $department;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 9, 10)->startOfDay());
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->mda = Mda::factory()->create();
        $this->department = Department::query()->create(['mda_id' => $this->mda->id, 'code' => 'RET', 'name' => 'Retirement department']);
        $this->user = User::factory()->mdaUser($this->mda, 'report_viewer')->create();
        $this->user->givePermissionTo('view-reports');
    }

    public function test_projection_list_matches_the_bar_and_uses_only_current_visible_staff(): void
    {
        $first = $this->staff('FIRST', '2026-01-01');
        $last = $this->staff('LAST', '2026-12-31');
        $this->staff('NEXT', '2027-01-01');
        $this->staff('RETIRED', '2026-06-01', ['status' => 'retired']);
        $this->staff('RETIRED-EMPLOYMENT', '2026-06-01', [], ['employment_status' => 'retired']);
        $this->staff('OLD-EMPLOYMENT', '2026-06-01', [], ['is_current' => false]);
        $this->staff('DELETED', '2026-06-01')->delete();
        $this->staff('HIDDEN', '2026-06-01', ['mda_id' => Mda::factory()->create()->id]);
        $first->employments()->create(['mda_id' => $this->mda->id, 'expected_retirement_date' => '2026-03-01', 'is_current' => false]);

        $response = $this->actingAs($this->user)->getJson('/api/dashboard/retirement-staff?year=2026')->assertOk()
            ->assertJsonPath('meta.total', 2)->assertJsonPath('meta.kind', 'projection')
            ->assertJsonPath('data.0.retirement_date', '2026-01-01')
            ->assertJsonPath('data.0.retirement_date_source', 'Expected')
            ->assertJsonPath('data.0.can_view_record', false);
        $this->assertSame([$first->id, $last->id], array_column($response->json('data'), 'id'));
        $this->assertBarCount(2026, 'projection', 2);
        $this->user->givePermissionTo('view-staff');
        $this->getJson('/api/dashboard/retirement-staff?year=2026')->assertOk()->assertJsonPath('data.0.can_view_record', true);
    }

    public function test_history_list_uses_earliest_recorded_retirement_then_expected_date_fallback(): void
    {
        $recorded = $this->staff('A-RECORDED', '2028-05-01', ['status' => 'retired']);
        $recorded->statusHistories()->create(['status' => 'retired', 'effective_from' => '2025-01-01']);
        $recorded->statusHistories()->create(['status' => 'retired', 'effective_from' => '2025-09-01']);
        $fallback = $this->staff('B-FALLBACK', '2025-12-31');
        $fallback->statusHistories()->create(['status' => 'active', 'effective_from' => '2010-01-01']);
        $earlier = $this->staff('EARLIER', '2025-06-01');
        $earlier->statusHistories()->create(['status' => 'retired', 'effective_from' => '2024-06-01']);
        $earlier->statusHistories()->create(['status' => 'retired', 'effective_from' => '2025-06-01']);
        $this->staff('NEXT', '2026-01-01');
        $this->staff('OLD', '2025-06-01', [], ['is_current' => false]);
        $this->staff('HIDDEN', '2025-06-01', ['mda_id' => Mda::factory()->create()->id]);
        $this->staff('DELETED', '2025-06-01')->delete();

        $response = $this->actingAs($this->user)->getJson('/api/dashboard/retirement-staff?year=2025')->assertOk()
            ->assertJsonPath('meta.total', 2)->assertJsonPath('meta.kind', 'history')
            ->assertJsonPath('data.0.retirement_date', '2025-01-01')->assertJsonPath('data.0.retirement_date_source', 'Recorded')
            ->assertJsonPath('data.1.retirement_date', '2025-12-31')->assertJsonPath('data.1.retirement_date_source', 'Expected');
        $this->assertSame([$recorded->id, $fallback->id], array_column($response->json('data'), 'id'));
        $this->assertBarCount(2025, 'history', 2);
    }

    public function test_retirement_lists_and_bars_respect_mda_and_department_tenancy(): void
    {
        $visible = $this->staff('VISIBLE', '2027-01-01');
        $visibleHistory = $this->staff('VISIBLE-HISTORY', '2025-01-01');
        $otherDepartment = Department::query()->create(['mda_id' => $this->mda->id, 'code' => 'HIDDEN', 'name' => 'Hidden department']);
        $this->staff('HIDDEN-DEPT', '2027-01-01', [], ['department_id' => $otherDepartment->id]);
        $this->staff('HIDDEN-DEPT-HISTORY', '2025-01-01', [], ['department_id' => $otherDepartment->id]);
        $this->staff('HIDDEN-MDA', '2027-01-01', ['mda_id' => Mda::factory()->create()->id]);
        $this->user->accessScopes()->create(['scope_type' => 'department', 'mda_id' => $this->mda->id, 'department_id' => $this->department->id]);
        $this->actingAs($this->user)->getJson('/api/dashboard/retirement-staff?year=2027')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $visible->id);
        $this->getJson('/api/dashboard/retirement-staff?year=2025')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $visibleHistory->id);
        $this->assertBarCount(2027, 'projection', 1);
        $this->assertBarCount(2025, 'history', 1);
    }

    public function test_multi_mda_access_includes_only_assigned_mdas(): void
    {
        $first = $this->staff('A-VISIBLE', '2027-01-01');
        $secondMda = Mda::factory()->create();
        $second = $this->staff('B-VISIBLE', '2027-01-01', ['mda_id' => $secondMda->id]);
        $this->staff('HIDDEN', '2027-01-01', ['mda_id' => Mda::factory()->create()->id]);
        $this->user->accessScopes()->create(['scope_type' => 'mda', 'mda_id' => $secondMda->id]);
        $response = $this->actingAs($this->user)->getJson('/api/dashboard/retirement-staff?year=2027')->assertOk()->assertJsonPath('meta.total', 2);
        $this->assertSame([$first->id, $second->id], array_column($response->json('data'), 'id'));
        $this->assertBarCount(2027, 'projection', 2);
    }

    public function test_list_is_paginated_and_zero_count_year_returns_an_empty_list(): void
    {
        for ($i = 1; $i <= 23; $i++) {
            $this->staff(sprintf('RET-%02d', $i), '2027-06-01');
        }
        $this->actingAs($this->user)->getJson('/api/dashboard/retirement-staff?year=2027&per_page=20')->assertOk()
            ->assertJsonCount(20, 'data')->assertJsonPath('meta.total', 23)->assertJsonPath('meta.last_page', 2);
        $this->getJson('/api/dashboard/retirement-staff?year=2027&per_page=20&page=2')->assertOk()
            ->assertJsonCount(3, 'data')->assertJsonPath('data.0.staff_number', 'RET-21')->assertJsonPath('meta.from', 21)->assertJsonPath('meta.to', 23);
        $this->getJson('/api/dashboard/retirement-staff?year=2028')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
    }

    public function test_permission_station_access_and_chart_year_limits_are_enforced(): void
    {
        $this->getJson('/api/dashboard/retirement-staff?year=2027')->assertUnauthorized();
        $this->user->revokePermissionTo('view-reports');
        $this->actingAs($this->user)->getJson('/api/dashboard/retirement-staff?year=2027')->assertForbidden();
        $this->user->givePermissionTo('view-reports');
        $this->getJson('/api/dashboard/retirement-staff')->assertUnprocessable()->assertJsonValidationErrors('year');
        $this->getJson('/api/dashboard/retirement-staff?year=2031&per_page=10000&page=0')->assertUnprocessable()->assertJsonValidationErrors(['year', 'per_page', 'page']);
        $this->getJson('/api/dashboard/retirement-staff?year=2020')->assertUnprocessable()->assertJsonValidationErrors('year');
        $this->getJson('/api/dashboard/retirement-staff?year=2021')->assertOk();
        $this->getJson('/api/dashboard/retirement-staff?year=2030')->assertOk();
        $station = Station::query()->create(['mda_id' => $this->mda->id, 'code' => 'FAC', 'name' => 'Assigned facility']);
        $this->user->forceFill(['station_id' => $station->id])->save();
        $this->getJson('/api/dashboard/retirement-staff?year=2027')->assertForbidden();
    }

    protected function staff(string $number, string $date, array $attributes = [], array $employment = []): Staff
    {
        $staff = Staff::query()->create(array_merge([
            'mda_id' => $this->mda->id, 'staff_number' => $number, 'surname' => 'Officer',
            'first_name' => $number, 'full_name' => $number.' Officer', 'status' => 'active',
        ], $attributes));
        $staff->employments()->create(array_merge([
            'mda_id' => $staff->mda_id,
            'department_id' => $staff->mda_id === $this->mda->id ? $this->department->id : null,
            'expected_retirement_date' => $date, 'employment_status' => 'active', 'is_current' => true,
        ], $employment));

        return $staff;
    }

    protected function assertBarCount(int $year, string $kind, int $count): void
    {
        $response = $this->getJson('/api/dashboard')->assertOk();
        $bar = collect($response->json('data.retirement_trends.'.$kind))->firstWhere('label', (string) $year);
        $this->assertSame($count, $bar['total']);
    }
}
