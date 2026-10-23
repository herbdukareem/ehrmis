<?php

namespace Tests\Concerns;

use App\Domain\ExecutiveOffice\Models\Office;
use App\Domain\ExecutiveOffice\Services\OfficeAdministrationService;
use App\Domain\Module\Models\Module;
use App\Domain\Organization\Models\Mda;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

trait BuildsExecutiveOffice
{
    protected Mda $mda;

    protected Office $office;

    protected User $admin;

    protected User $executive;

    protected User $pa;

    protected User $officer;

    protected User $registry;

    protected function officeFixtures(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->mda = Mda::factory()->create();
        Module::query()->where('code', 'executive_office')->firstOrFail()->mdas()->attach($this->mda->id, ['enabled' => true]);
        $this->admin = User::factory()->mdaUser($this->mda)->create();
        $this->admin->givePermissionTo(['manage-executive-office', 'view-executive-office']);
        $service = app(OfficeAdministrationService::class);
        $this->office = $service->configure($this->admin, $this->mda->id, ['title' => 'Executive Secretary', 'reference_prefix' => 'TEST/EO', 'timezone' => 'Africa/Lagos', 'pa_confirms_routine' => true, 'reminder_minutes' => 60]);
        foreach (['executive' => 'executive', 'pa' => 'lead_pa', 'officer' => 'action_officer', 'registry' => 'registry'] as $property => $role) {
            $this->$property = User::factory()->mdaUser($this->mda)->create();
            $service->assign($this->admin, $this->office, ['user_id' => $this->$property->id, 'role' => $role, 'starts_at' => now()->subDay()->toDateTimeString()]);
        }
    }

    protected function officeUrl(string $path = ''): string
    {
        return '/api/executive-office/'.$path.'?mda_id='.$this->mda->id;
    }
}
