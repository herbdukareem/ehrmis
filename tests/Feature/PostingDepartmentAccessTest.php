<?php

namespace Tests\Feature;

use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Mda;
use App\Domain\Organization\Models\Station;
use App\Domain\Posting\Models\StaffPostingRequest;
use App\Domain\Staff\Models\Staff;
use App\Domain\Staff\Models\StaffEmployment;
use App\Models\User;
use App\Models\UserAccessScope;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PostingDepartmentAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_department_scoped_user_cannot_view_or_submit_postings_for_other_departments(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $mda = Mda::query()->create(['code' => 'HMB', 'name' => 'Hospital Management Board', 'status' => 'active']);
        $departmentA = Department::query()->create(['mda_id' => $mda->id, 'code' => 'NUR', 'name' => 'Nursing', 'status' => 'active']);
        $departmentB = Department::query()->create(['mda_id' => $mda->id, 'code' => 'MED', 'name' => 'Medical', 'status' => 'active']);
        $station = Station::query()->create(['mda_id' => $mda->id, 'code' => 'HQ', 'name' => 'Headquarters', 'status' => 'active']);

        $staffA = $this->staff($mda, $departmentA, $station, 'Nursing Officer');
        $staffB = $this->staff($mda, $departmentB, $station, 'Medical Officer');

        $visiblePosting = $this->posting($mda, $departmentA, $station, $staffA);
        $hiddenPosting = $this->posting($mda, $departmentB, $station, $staffB);

        $user = User::factory()->mdaUser($mda)->create();
        $user->assignRole('MDA Admin');
        UserAccessScope::query()->create([
            'user_id' => $user->id,
            'scope_type' => 'department',
            'mda_id' => $mda->id,
            'department_id' => $departmentA->id,
        ]);

        $index = $this->actingAs($user)
            ->getJson('/api/posting-requests')
            ->assertOk();

        $this->assertContains($visiblePosting->id, array_column($index->json('data'), 'id'));
        $this->assertNotContains($hiddenPosting->id, array_column($index->json('data'), 'id'));
        $this->assertSame([$staffA->id], array_column($index->json('options.staff'), 'id'));
        $this->assertSame([$departmentA->id], array_column($index->json('options.departments'), 'id'));

        $this->actingAs($user)
            ->getJson('/api/posting-requests/'.$hiddenPosting->id)
            ->assertForbidden();

        $this->actingAs($user)
            ->postJson('/api/posting-requests', [
                'staff_id' => $staffB->id,
                'to_mda_id' => $mda->id,
                'to_department_id' => $departmentA->id,
                'to_station_id' => $station->id,
                'effective_date' => '2026-10-01',
            ])
            ->assertForbidden();

        $this->actingAs($user)
            ->postJson('/api/posting-requests', [
                'staff_id' => $staffA->id,
                'to_mda_id' => $mda->id,
                'to_department_id' => $departmentB->id,
                'to_station_id' => $station->id,
                'effective_date' => '2026-10-01',
            ])
            ->assertForbidden();

        $admin = User::factory()->mdaUser($mda)->create();
        $admin->assignRole('MDA Admin');

        $adminIndex = $this->actingAs($admin)
            ->getJson('/api/posting-requests')
            ->assertOk();

        $this->assertEqualsCanonicalizing(
            [$visiblePosting->id, $hiddenPosting->id],
            array_column($adminIndex->json('data'), 'id')
        );
        $this->assertEqualsCanonicalizing(
            [$departmentA->id, $departmentB->id],
            array_column($adminIndex->json('options.departments'), 'id')
        );

        $this->actingAs($admin)
            ->getJson('/api/posting-requests/'.$hiddenPosting->id)
            ->assertOk();
    }

    protected function staff(Mda $mda, Department $department, Station $station, string $name): Staff
    {
        $staff = Staff::query()->create([
            'mda_id' => $mda->id,
            'staff_number' => 'STF-'.Str::upper(Str::random(6)),
            'surname' => Str::before($name, ' '),
            'first_name' => Str::after($name, ' ') ?: 'Officer',
            'full_name' => $name,
            'status' => 'active',
        ]);

        StaffEmployment::query()->create([
            'staff_id' => $staff->id,
            'mda_id' => $mda->id,
            'department_id' => $department->id,
            'station_id' => $station->id,
            'employment_status' => 'active',
            'is_current' => true,
            'effective_from' => '2026-01-01',
        ]);

        return $staff;
    }

    protected function posting(Mda $mda, Department $department, Station $station, Staff $staff): StaffPostingRequest
    {
        return StaffPostingRequest::query()->create([
            'staff_id' => $staff->id,
            'request_number' => 'PO-'.Str::upper(Str::random(8)),
            'posting_type' => 'department_transfer',
            'from_mda_id' => $mda->id,
            'from_department_id' => $department->id,
            'from_station_id' => $station->id,
            'to_mda_id' => $mda->id,
            'to_department_id' => $department->id,
            'to_station_id' => $station->id,
            'effective_date' => '2026-10-01',
            'staff_snapshot' => ['staff_id' => $staff->id, 'primary_staff_name' => $staff->full_name],
            'status' => 'draft',
        ]);
    }
}
