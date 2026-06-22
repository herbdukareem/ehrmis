<?php

namespace Tests\Feature;

use App\Domain\Legacy\Models\LegacyStaffImportBatch;
use App\Domain\Legacy\Models\LegacyStaffImportError;
use App\Domain\Legacy\Models\LegacyStaffImportRow;
use App\Domain\Organization\Models\Mda;
use App\Domain\Staff\Models\AllowanceType;
use App\Domain\Staff\Models\Staff;
use App\Domain\Staff\Services\StaffAllowanceService;
use App\Http\Resources\StaffDetailResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StaffAllowanceServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_allowance_sync_uses_allowance_type_id_and_fixed_allowance_columns_do_not_exist(): void
    {
        $mda = Mda::query()->create(['code' => 'MOH', 'name' => 'MINISTRY OF HEALTH', 'status' => 'active']);
        $hazard = AllowanceType::query()->create(['mda_id' => $mda->id, 'code' => 'hazard', 'name' => 'Hazard Allowance', 'status' => 'active']);
        $rural = AllowanceType::query()->create(['mda_id' => $mda->id, 'code' => 'rural', 'name' => 'Rural Allowance', 'status' => 'active']);

        $staff = Staff::withoutGlobalScopes()->create([
            'mda_id' => $mda->id,
            'staff_number' => 'STF001',
            'surname' => 'Allowance',
            'first_name' => 'User',
            'full_name' => 'Allowance User',
            'status' => 'active',
        ]);

        app(StaffAllowanceService::class)->syncAssignments($staff, [
            [
                'allowance_type_id' => $hazard->id,
                'is_eligible' => true,
                'source' => 'staff_management',
                'effective_from' => '2026-01-01',
            ],
            [
                'allowance_type_id' => $rural->id,
                'is_eligible' => false,
                'source' => 'staff_management',
                'effective_from' => '2026-01-01',
            ],
        ]);

        $this->assertDatabaseHas('staff_allowance_assignments', [
            'staff_id' => $staff->id,
            'allowance_type_id' => $hazard->id,
            'is_eligible' => true,
        ]);

        $this->assertFalse(Schema::hasColumn('staff_allowance_assignments', 'call'));
        $this->assertFalse(Schema::hasColumn('staff_allowance_assignments', 'shift'));
        $this->assertFalse(Schema::hasColumn('staff_allowance_assignments', 'allowance_code'));
    }

    public function test_unresolved_call_allowance_is_not_force_mapped(): void
    {
        $mda = Mda::query()->create(['code' => 'MOH', 'name' => 'MINISTRY OF HEALTH', 'status' => 'active']);
        $staff = Staff::withoutGlobalScopes()->create([
            'mda_id' => $mda->id,
            'staff_number' => 'STF002',
            'legacy_cno_psn' => 'C001P001',
            'surname' => 'Call',
            'first_name' => 'Warning',
            'full_name' => 'Call Warning',
            'status' => 'active',
        ]);

        $batch = LegacyStaffImportBatch::query()->create([
            'source_database' => 'ministry_of_health',
            'source_table' => 'staff_list',
            'status' => 'completed',
        ]);

        $row = LegacyStaffImportRow::query()->create([
            'batch_id' => $batch->id,
            'dedupe_key' => 'C001P001',
            'status' => 'published',
            'published_staff_id' => $staff->id,
            'raw_payload' => ['call_allowance' => 'YES'],
            'normalized_payload' => ['allowances' => []],
        ]);

        LegacyStaffImportError::query()->create([
            'batch_id' => $batch->id,
            'row_id' => $row->id,
            'field' => 'call_allowance',
            'error_code' => 'call_allowance_unresolved',
            'message' => 'Call allowance needs clarification.',
            'severity' => 'warning',
        ]);

        $detail = StaffDetailResource::make($staff->fresh())->resolve();

        $this->assertTrue($detail['import_metadata']['needs_call_allowance_clarification']);
        $this->assertSame([], array_filter(
            $detail['allowance_assignments'],
            fn (array $assignment): bool => str_starts_with((string) ($assignment['allowance_code'] ?? ''), 'call_')
        ));
    }
}
