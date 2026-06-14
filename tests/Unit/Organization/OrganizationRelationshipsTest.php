<?php

namespace Tests\Unit\Organization;

use App\Domain\Organization\Models\Location;
use App\Domain\Organization\Models\Mda;
use App\Domain\Organization\Models\Station;
use App\Domain\Organization\Models\Department;
use App\Domain\Staff\Models\Cadre;
use App\Domain\Staff\Models\Rank;
use App\Domain\Staff\Models\SalaryScale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OrganizationRelationshipsTest extends TestCase
{
    use RefreshDatabase;

    public function test_organization_relationships_are_wired_correctly(): void
    {
        $mda = Mda::factory()->create();
        $department = Department::factory()->create(['mda_id' => $mda->id]);
        $station = Station::factory()->create(['mda_id' => $mda->id]);
        $location = Location::factory()->create();
        $salaryScale = SalaryScale::query()->create([
            'code' => 'GL',
            'name' => 'GRADE LEVEL',
            'min_level' => 1,
            'max_level' => 17,
            'min_step' => 1,
            'max_step' => 15,
            'status' => 'active',
        ]);
        $cadre = Cadre::query()->create([
            'salary_scale_id' => $salaryScale->id,
            'department_id' => $department->id,
            'name' => 'ADMIN OFFICER',
            'legacy_department_name' => 'ADMIN',
            'status' => 'active',
        ]);
        $rank = Rank::query()->create([
            'cadre_id' => $cadre->id,
            'salary_scale_id' => $salaryScale->id,
            'name' => 'A.O I',
            'level' => 9,
            'status' => 'active',
        ]);

        $this->assertTrue($station->mda->is($mda));
        $this->assertFalse(Schema::hasColumn('stations', 'department_id'));
        $this->assertNotNull($location->id);
        $this->assertTrue($cadre->salaryScale->is($salaryScale));
        $this->assertTrue($cadre->department->is($department));
        $this->assertTrue($rank->cadre->is($cadre));
        $this->assertTrue($rank->salaryScale->is($salaryScale));
    }
}
