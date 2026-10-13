<?php

namespace Tests\Feature;

use App\Domain\Budget\Models\BudgetAccount;
use App\Domain\Budget\Models\BudgetAllocation;
use App\Domain\Budget\Models\BudgetLine;
use App\Domain\Budget\Models\BudgetTransaction;
use App\Domain\Budget\Models\BudgetWorkbook;
use App\Domain\Budget\Services\BudgetLedgerService;
use App\Domain\Budget\Services\BudgetWorkbookWorkflowService;
use App\Domain\Budget\Services\RecurrentBudgetAllocationSyncService;
use App\Domain\Movement\Models\MovementWorkbook;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Mda;
use App\Domain\Organization\Models\MdaSetting;
use App\Domain\Staff\Models\SalaryScale;
use App\Models\User;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BudgetRecurrentAllocationSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_approved_recurrent_budget_syncs_personnel_allocations_when_enabled(): void
    {
        [$actor, $workbook, $line] = $this->workbook(linkEnabled: true, amount: 150000);

        app(BudgetWorkbookWorkflowService::class)->approve($workbook, $actor);

        $account = BudgetAccount::query()->where('code', 'PERSONNEL')->firstOrFail();
        $allocation = BudgetAllocation::query()
            ->where('source_type', BudgetLine::class)
            ->where('source_id', $line->id)
            ->firstOrFail();

        $this->assertSame($workbook->mda_id, $account->mda_id);
        $this->assertSame('personnel', $account->account_type);
        $this->assertSame('approved', $allocation->status);
        $this->assertSame($line->department_id, $allocation->department_id);
        $this->assertEquals(150000.0, (float) $allocation->approved_amount);
        $this->assertDatabaseHas('budget_cycles', [
            'mda_id' => $workbook->mda_id,
            'year' => $workbook->year,
        ]);
        $this->assertDatabaseHas('budget_transactions', [
            'budget_allocation_id' => $allocation->id,
            'transaction_type' => BudgetLedgerService::TYPE_ALLOCATION,
            'signed_amount' => 150000,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event_code' => 'budget.recurrent_allocations_synced',
            'auditable_type' => BudgetWorkbook::class,
            'auditable_id' => $workbook->id,
        ]);
    }

    public function test_recurrent_budget_approval_does_not_sync_when_mda_setting_is_disabled(): void
    {
        [$actor, $workbook] = $this->workbook(linkEnabled: false, amount: 150000);

        app(BudgetWorkbookWorkflowService::class)->approve($workbook, $actor);

        $this->assertSame(0, BudgetAllocation::query()->count());
        $this->assertSame(0, BudgetTransaction::query()->count());
    }

    public function test_recurrent_budget_sync_updates_existing_allocation_and_ledger_transaction(): void
    {
        [$actor, $workbook, $line] = $this->workbook(linkEnabled: true, amount: 150000);
        $sync = app(RecurrentBudgetAllocationSyncService::class);

        app(BudgetWorkbookWorkflowService::class)->approve($workbook, $actor);

        $line->forceFill(['proposed_gross_total' => 175000, 'variance_total' => 175000])->save();
        $summary = $sync->syncApprovedWorkbook($workbook->fresh(), $actor);

        $allocation = BudgetAllocation::query()
            ->where('source_type', BudgetLine::class)
            ->where('source_id', $line->id)
            ->firstOrFail();

        $this->assertSame(1, $summary['allocations_synced']);
        $this->assertEquals(175000.0, $summary['amount_synced']);
        $this->assertSame(1, BudgetAllocation::query()->count());
        $this->assertSame(1, BudgetTransaction::query()->where('transaction_type', BudgetLedgerService::TYPE_ALLOCATION)->count());
        $this->assertEquals(175000.0, (float) $allocation->approved_amount);
        $this->assertDatabaseHas('budget_transactions', [
            'budget_allocation_id' => $allocation->id,
            'transaction_type' => BudgetLedgerService::TYPE_ALLOCATION,
            'signed_amount' => 175000,
        ]);
    }

    public function test_mda_settings_endpoint_updates_recurrent_budget_link_toggle(): void
    {
        [$actor, $workbook] = $this->workbook(linkEnabled: false, amount: 150000);

        $this->actingAs($actor)
            ->postJson("/api/settings/mdas/{$workbook->mda_id}", [
                'name' => 'Ministry of Health',
                'code' => 'MOH',
                'acronym' => 'MOH',
                'link_recurrent_budget_to_annual_allocations' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.setting.link_recurrent_budget_to_annual_allocations', true);

        $this->assertTrue((bool) MdaSetting::query()->where('mda_id', $workbook->mda_id)->value('link_recurrent_budget_to_annual_allocations'));
    }

    /**
     * @return array{0: User, 1: BudgetWorkbook, 2: BudgetLine}
     */
    protected function workbook(bool $linkEnabled, float $amount): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $mda = Mda::query()->create([
            'code' => 'MOH',
            'name' => 'Ministry of Health',
            'status' => 'active',
        ]);
        MdaSetting::query()->create([
            'mda_id' => $mda->id,
            'acronym' => 'MOH',
            'link_recurrent_budget_to_annual_allocations' => $linkEnabled,
        ]);
        $this->seed(ModuleSeeder::class);
        $actor = User::factory()->mdaUser($mda)->create();
        $actor->assignRole('MDA Admin');
        $department = Department::query()->create([
            'mda_id' => $mda->id,
            'code' => 'ADMIN',
            'name' => 'Administration',
            'status' => 'active',
        ]);
        $scale = SalaryScale::query()->firstOrCreate(['code' => 'GL'], [
            'name' => 'Grade Level',
            'min_level' => 1,
            'max_level' => 17,
            'min_step' => 1,
            'max_step' => 15,
            'status' => 'active',
        ]);
        $movement = MovementWorkbook::query()->create([
            'mda_id' => $mda->id,
            'year' => 2026,
            'status' => 'approved',
        ]);
        $workbook = BudgetWorkbook::query()->create([
            'mda_id' => $mda->id,
            'movement_workbook_id' => $movement->id,
            'year' => 2026,
            'status' => 'draft',
            'generated_by' => $actor->id,
            'generated_at' => now(),
            'summary' => ['proposed_gross_total' => $amount],
        ]);
        $line = BudgetLine::query()->create([
            'workbook_id' => $workbook->id,
            'department_id' => $department->id,
            'salary_scale_id' => $scale->id,
            'level' => 9,
            'staff_count' => 3,
            'retiring_count' => 0,
            'required_staff_count' => 3,
            'current_gross_total' => 0,
            'proposed_gross_total' => $amount,
            'variance_total' => $amount,
        ]);

        return [$actor, $workbook, $line];
    }
}
