<?php

namespace Tests\Feature\Audit;

use App\Domain\Organization\Models\Mda;
use App\Models\User;
use App\Services\AuditLogService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_log_can_be_created(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->superAdmin()->create();
        $user->assignRole('Super Admin');
        $mda = Mda::factory()->create();

        $this->actingAs($user);

        app(AuditLogService::class)->logCreated($mda, [
            'module' => 'organization',
            'action' => 'seed',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $user->id,
            'event_code' => 'created',
            'auditable_type' => Mda::class,
            'auditable_id' => $mda->id,
        ]);
    }
}
