<?php

namespace Tests\Feature\Console;

use App\Domain\Movement\Models\MovementWorkbook;
use App\Domain\Organization\Models\Mda;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateMovementWorkbookStatusCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_updates_workbook_status(): void
    {
        $mda = Mda::query()->create([
            'code' => 'MOH',
            'name' => 'MINISTRY OF HEALTH',
            'status' => 'active',
        ]);

        $workbook = MovementWorkbook::query()->create([
            'mda_id' => $mda->id,
            'year' => 2026,
            'status' => 'draft',
        ]);

        $this
            ->artisan('movement:update-workbook-status', [
                'workbook' => $workbook->id,
                'action' => 'reviewed',
            ])
            ->expectsOutputToContain('Movement workbook status updated successfully.')
            ->assertSuccessful();

        $this->assertDatabaseHas('movement_workbooks', [
            'id' => $workbook->id,
            'status' => 'reviewed',
        ]);
    }
}
