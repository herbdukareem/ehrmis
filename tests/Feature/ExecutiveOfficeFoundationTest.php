<?php

namespace Tests\Feature;

use App\Domain\ExecutiveOffice\Models\Office;
use App\Domain\ExecutiveOffice\Services\OfficeAccess;
use App\Domain\Module\Models\MdaModule;
use App\Domain\Organization\Models\Mda;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsExecutiveOffice;
use Tests\TestCase;

class ExecutiveOfficeFoundationTest extends TestCase
{
    use BuildsExecutiveOffice, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->officeFixtures();
    }

    public function test_one_office_per_mda_and_setup_does_not_grant_record_access(): void
    {
        $this->actingAs($this->admin)->putJson($this->officeUrl('office'), ['title' => 'General Manager', 'reference_prefix' => 'GM', 'timezone' => 'Africa/Lagos', 'pa_confirms_routine' => true, 'reminder_minutes' => 30])->assertOk();
        $this->assertSame(1, Office::query()->where('mda_id', $this->mda->id)->count());
        $this->assertSame([], app(OfficeAccess::class)->abilities($this->admin, $this->office));
        $this->getJson('/api/executive-office/context')->assertOk()->assertJsonPath('data.0.can_configure', true)->assertJsonPath('data.0.abilities', []);
    }

    public function test_team_access_requires_membership_and_module_activation(): void
    {
        $this->actingAs($this->pa)->getJson('/api/executive-office/context')->assertOk()->assertJsonPath('data.0.roles.0', 'lead_pa');
        $this->getJson($this->officeUrl('team'))->assertForbidden();
        MdaModule::query()->where('mda_id', $this->mda->id)->whereHas('module', fn ($q) => $q->where('code', 'executive_office'))->update(['enabled' => false]);
        $this->getJson('/api/executive-office/context')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($this->officeUrl('team'))->assertForbidden();
    }

    public function test_tenant_isolation_and_unique_dated_executive_assignments(): void
    {
        $other = User::factory()->mdaUser(Mda::factory()->create())->create();
        $this->actingAs($this->admin)->postJson($this->officeUrl('team'), ['user_id' => $other->id, 'role' => 'pa', 'starts_at' => now()->toDateTimeString()])->assertUnprocessable();
        $this->postJson($this->officeUrl('team'), ['user_id' => $this->pa->id, 'role' => 'executive', 'starts_at' => now()->toDateTimeString()])->assertUnprocessable();
        $this->actingAs($other)->getJson($this->officeUrl('team'))->assertForbidden();
    }

    public function test_membership_expiry_and_revocation_remove_authority_immediately(): void
    {
        $member = $this->office->memberships()->where('user_id', $this->pa->id)->firstOrFail();
        $this->actingAs($this->admin)->deleteJson($this->officeUrl('team/'.$member->id))->assertOk();
        $this->assertSame([], app(OfficeAccess::class)->abilities($this->pa, $this->office));
        $this->office->memberships()->where('user_id', $this->executive->id)->update(['ends_at' => now()]);
        $this->assertFalse(app(OfficeAccess::class)->executive($this->executive, $this->office));
    }
}
