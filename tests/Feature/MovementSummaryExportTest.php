<?php

namespace Tests\Feature;

use App\Domain\Movement\Models\MovementLine;
use App\Domain\Movement\Models\MovementWorkbook;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Mda;
use App\Domain\Staff\Models\SalaryScale;
use App\Domain\Staff\Models\Staff;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use Tests\TestCase;

class MovementSummaryExportTest extends TestCase
{
    use RefreshDatabase;

    protected Mda $mda;

    protected User $user;

    protected MovementWorkbook $workbook;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->mda = Mda::factory()->create();
        $this->user = User::factory()->mdaUser($this->mda, 'report_viewer')->create();
        $this->user->givePermissionTo('view-movement-sheets');
        $this->workbook = MovementWorkbook::query()->create([
            'mda_id' => $this->mda->id, 'name' => 'Movement export test', 'year' => 2026,
            'budget_year' => 2027, 'budget_minimum_step' => 6, 'status' => 'approved',
        ]);
    }

    public function test_all_departments_have_separate_formatted_sheets_with_matching_movement_counts(): void
    {
        $admin = $this->department('Administration');
        $medical = $this->department('Medical');
        $this->line($admin, overrides: ['current_level' => 2, 'proposed_level' => 3]);
        $this->line($admin, overrides: ['current_level' => 2, 'proposed_level' => 2]);
        $this->line($admin, overrides: ['current_level' => 2, 'retirement_status' => 'retiring']);
        $this->line($admin, overrides: ['selection_state' => 'excluded']);
        $this->line($admin, 'CARE');
        $this->line($medical);

        $book = $this->download();
        try {
            $this->assertSame(['Administration', 'Medical'], $book->getSheetNames());
            foreach ($book->getAllSheets() as $sheet) {
                $this->assertNull($sheet->getFreezePane());
            }
            $sheet = $book->getSheetByName('Administration');
            $this->assertSame('2026 Movement Sheet - Department Summary', $sheet->getCell('A3')->getValue());
            $this->assertStringContainsString('Budget year: 2027 | Budget minimum: Step 6 | Status: APPROVED', $sheet->getCell('A4')->getValue());
            $this->assertSame("Present No.\nof Staff", $sheet->getCell('D7')->getValue());
            $this->assertSame(13, $sheet->getHighestRow());
            $this->assertEquals([
                [1, 'CARE', 2, 0, 0, 0, 0, 0],
                [2, 'CARE', 1, 1, 0, 0, 0, 1],
                [3, 'MOVE', 3, 0, 0, 0, 1, 1],
                [4, 'MOVE', 2, 3, 1, 1, 0, 1],
                [5, 'MOVE', 1, 1, 0, 0, 0, 1],
            ], $sheet->rangeToArray('A8:H12', null, true, false));
            $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('D8')->getDataType());
            $this->assertSame('0', $sheet->getCell('D8')->getFormattedValue());
            $this->assertSame('=SUM(D8:D12)', $sheet->getCell('D13')->getValue());
            $this->assertEquals([5, 1, 1, 1, 4], $sheet->rangeToArray('D13:H13', null, true, false)[0]);
            foreach ($book->getAllSheets() as $departmentSheet) {
                foreach (range('A', 'H') as $column) {
                    $header = $departmentSheet->getStyle($column.'7');
                    $this->assertTrue($header->getAlignment()->getWrapText());
                    $this->assertSame(Alignment::HORIZONTAL_CENTER, $header->getAlignment()->getHorizontal());
                    $this->assertTrue($header->getFont()->getBold());
                }
                $this->assertSame('#,##0', $departmentSheet->getStyle('D8')->getNumberFormat()->getFormatCode());
                $this->assertFalse($departmentSheet->getStyle('D8')->getFont()->getBold());
                $this->assertSame(Fill::FILL_NONE, $departmentSheet->getStyle('D8')->getFill()->getFillType());
                $this->assertSame(PageSetup::ORIENTATION_LANDSCAPE, $departmentSheet->getPageSetup()->getOrientation());
                $this->assertSame(PageSetup::PAPERSIZE_A4, $departmentSheet->getPageSetup()->getPaperSize());
                $this->assertSame(1, $departmentSheet->getPageSetup()->getFitToWidth());
                $this->assertSame(0, $departmentSheet->getPageSetup()->getFitToHeight());
                $this->assertSame(['1', '7'], $departmentSheet->getPageSetup()->getRowsToRepeatAtTop());
                $this->assertSame('A1:H'.$departmentSheet->getHighestRow(), $departmentSheet->getPageSetup()->getPrintArea());
            }
        } finally {
            $book->disconnectWorksheets();
        }
    }

    public function test_individual_department_export_only_contains_the_selected_department(): void
    {
        $admin = $this->department('Administration');
        $medical = $this->department('Medical');
        $this->line($admin);
        $this->line($medical);
        $book = $this->download('?department_id='.$medical->id, 'movement-export-test-medical-summary.xlsx');
        try {
            $this->assertSame(['Medical'], $book->getSheetNames());
            $this->assertSame('Medical', $book->getActiveSheet()->getCell('A5')->getValue());
            $this->assertSame("No. of Staff\nMoving", $book->getActiveSheet()->getCell('E7')->getValue());
        } finally {
            $book->disconnectWorksheets();
        }
    }

    public function test_retired_staff_can_be_marked_as_contract_or_special_movement(): void
    {
        $this->user->givePermissionTo('create-movement-sheets');
        $this->workbook->update(['status' => 'draft']);
        $admin = $this->department('Administration');
        $this->line($admin, overrides: [
            'current_amounts' => ['calculated_gross' => 100],
            'proposed_amounts' => ['calculated_gross' => 120],
        ]);
        $contract = $this->line($admin, overrides: [
            'selection_state' => 'excluded',
            'retirement_status' => 'retired',
            'current_amounts' => ['calculated_gross' => 80],
            'proposed_amounts' => ['calculated_gross' => 90],
        ]);
        $special = $this->line($admin, overrides: [
            'selection_state' => 'excluded',
            'retirement_status' => 'retired',
            'current_amounts' => ['calculated_gross' => 60],
            'proposed_amounts' => ['calculated_gross' => 70],
        ]);
        $ordinaryRetired = $this->line($admin, overrides: [
            'selection_state' => 'excluded',
            'retirement_status' => 'retired',
            'current_amounts' => ['calculated_gross' => 50],
            'proposed_amounts' => ['calculated_gross' => 55],
        ]);

        $this->actingAs($this->user)->patchJson('/api/movement-workbooks/'.$this->workbook->id.'/lines/'.$contract->id.'/flags', [
            'is_contract_staff' => true,
            'is_special_movement' => false,
        ])->assertOk()
            ->assertJsonPath('data.selection_state', 'included')
            ->assertJsonPath('data.eligibility_status', 'contract');

        $this->patchJson('/api/movement-workbooks/'.$this->workbook->id.'/lines/'.$special->id.'/flags', [
            'is_contract_staff' => false,
            'is_special_movement' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('proposed_level');

        $this->patchJson('/api/movement-workbooks/'.$this->workbook->id.'/lines/'.$special->id.'/flags', [
            'is_contract_staff' => false,
            'is_special_movement' => true,
            'proposed_level' => 2,
        ])->assertOk()
            ->assertJsonPath('data.selection_state', 'included')
            ->assertJsonPath('data.eligibility_status', 'due')
            ->assertJsonPath('data.proposed_level', 2);

        $summary = $this->workbook->summaries()->firstOrFail();
        $this->assertSame(3, $summary->staff_count);
        $this->assertSame(1, $summary->due_count);
        $this->assertSame(0, $summary->retiring_count);
        $this->assertSame(1, $summary->retired_count);
        $this->assertEquals(240.0, (float) $summary->current_gross_total);
        $this->assertEquals(280.0, (float) $summary->proposed_gross_total);

        $rows = app(\App\Domain\Movement\Services\MovementDepartmentSummaryService::class)
            ->summarize($this->workbook)
            ->firstWhere('department_id', $admin->id)['rows'];
        $row = collect($rows)->firstWhere('level', 1);
        $joiningRow = collect($rows)->firstWhere('level', 2);

        $this->assertSame(3, $row['present_staff']);
        $this->assertSame(1, $row['staff_moving']);
        $this->assertSame(0, $row['staff_retiring']);
        $this->assertSame(2, $row['expected_total']);
        $this->assertSame(1, $joiningRow['staff_joining']);
        $this->assertSame(1, $joiningRow['expected_total']);
        $this->assertFalse($ordinaryRetired->fresh()->countsAsCurrentStaff());
    }

    public function test_movement_detail_hides_ordinary_retired_staff_and_uses_staff_contract_flag(): void
    {
        $admin = $this->department('Administration');
        $active = $this->line($admin);
        $retiring = $this->line($admin, overrides: [
            'retirement_status' => 'retiring',
            'eligibility_status' => 'retiring',
        ]);
        $retired = $this->line($admin, overrides: [
            'selection_state' => 'excluded',
            'retirement_status' => 'retired',
            'eligibility_status' => 'retired',
        ]);
        $contract = $this->line($admin, overrides: [
            'selection_state' => 'included',
            'retirement_status' => 'retired',
            'eligibility_status' => 'retired',
        ]);

        $contract->staff->forceFill(['is_contract_staff' => true])->save();

        $response = $this->actingAs($this->user)
            ->getJson('/api/movement-workbooks/'.$this->workbook->id)
            ->assertOk();

        $lineIds = array_column($response->json('data.lines'), 'id');

        $this->assertContains($active->id, $lineIds);
        $this->assertContains($retiring->id, $lineIds);
        $this->assertContains($contract->id, $lineIds);
        $this->assertNotContains($retired->id, $lineIds);

        $contractPayload = collect($response->json('data.lines'))->firstWhere('id', $contract->id);

        $this->assertTrue($contractPayload['is_contract_staff']);
        $this->assertSame('contract', $contractPayload['eligibility_status']);
        $this->assertSame('Staff is marked as contract staff on the staff record.', $contractPayload['eligibility_reason']);
    }

    public function test_tab_names_are_safe_unique_and_unassigned_staff_are_included(): void
    {
        $this->mda->update(['name' => '=1+1']);
        $this->line($this->department('Clinical / Administration: North [1]'));
        $this->line($this->department('Clinical / Administration: North [2]'));
        $this->line($this->department('History'));
        $this->line(null);
        $book = $this->download();
        try {
            $titles = $book->getSheetNames();
            $this->assertCount(4, array_unique(array_map('strtolower', $titles)));
            $this->assertContains('Unassigned', $titles);
            $this->assertContains('History department', $titles);
            foreach ($titles as $title) {
                $this->assertLessThanOrEqual(31, mb_strlen($title));
                foreach (['\\', '/', '?', '*', ':', '[', ']'] as $invalid) {
                    $this->assertStringNotContainsString($invalid, $title);
                }
            }
            $this->assertSame('=1+1', $book->getSheet(0)->getCell('A2')->getValue());
            $this->assertSame(DataType::TYPE_STRING, $book->getSheet(0)->getCell('A2')->getDataType());
        } finally {
            $book->disconnectWorksheets();
        }
    }

    public function test_empty_workbook_exports_a_readable_sheet_and_draft_exports_remain_available(): void
    {
        $this->workbook->update(['status' => 'draft']);
        $book = $this->download();
        try {
            $this->assertSame(['No records'], $book->getSheetNames());
            $this->assertSame('No movement summary records match this export.', $book->getActiveSheet()->getCell('A8')->getValue());
            $this->assertContains('A8:H8', $book->getActiveSheet()->getMergeCells());
        } finally {
            $book->disconnectWorksheets();
        }
    }

    public function test_exports_require_permission_and_mda_access_and_cannot_export_foreign_departments(): void
    {
        $this->line($this->department('Visible department'));
        $otherMda = Mda::factory()->create();
        $otherWorkbook = MovementWorkbook::query()->create(['mda_id' => $otherMda->id, 'year' => 2026, 'status' => 'approved']);
        $foreignDepartment = Department::query()->create(['mda_id' => $otherMda->id, 'name' => 'Hidden department', 'code' => 'HIDDEN']);
        $this->actingAs($this->user)->get('/api/movement-workbooks/'.$otherWorkbook->id.'/summary-export')->assertForbidden();
        $book = $this->download('?department_id='.$foreignDepartment->id);
        try {
            $this->assertSame(['No records'], $book->getSheetNames());
            $this->assertStringNotContainsString('Hidden department', json_encode($book->getActiveSheet()->toArray()));
        } finally {
            $book->disconnectWorksheets();
        }
        $this->getJson($this->url().'?department_id=invalid')->assertUnprocessable();
        $this->user->revokePermissionTo('view-movement-sheets');
        $this->get($this->url())->assertForbidden();
    }

    protected function url(): string
    {
        return '/api/movement-workbooks/'.$this->workbook->id.'/summary-export';
    }

    protected function download(string $query = '', string $filename = 'movement-export-test-summary.xlsx'): Spreadsheet
    {
        $response = $this->actingAs($this->user)->get($this->url().$query)->assertOk()->assertDownload($filename);
        $path = $response->baseResponse->getFile()->getPathname();
        try {
            // Check the saved view directly; reading formula cells may change the reader's selection.
            $archive = new \ZipArchive;
            $archive->open($path);
            try {
                for ($index = 0; $index < $archive->numFiles; $index++) {
                    $entry = $archive->getNameIndex($index);
                    if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $entry)) {
                        $this->assertStringContainsString('activeCell="A1"', $archive->getFromName($entry));
                    }
                }
            } finally {
                $archive->close();
            }
            Cell::setValueBinder(new DefaultValueBinder);

            return IOFactory::load($path);
        } finally {
            unlink($path);
        }
    }

    protected function department(string $name): Department
    {
        return Department::query()->create(['mda_id' => $this->mda->id, 'code' => fake()->unique()->lexify('???'), 'name' => $name]);
    }

    protected function line(?Department $department, string $scaleCode = 'MOVE', array $overrides = []): MovementLine
    {
        $scale = SalaryScale::query()->firstOrCreate(['code' => $scaleCode], [
            'name' => $scaleCode, 'min_level' => 1, 'max_level' => $scaleCode === 'CARE' ? 2 : 3, 'min_step' => 1, 'max_step' => 15, 'status' => 'active',
        ]);
        $staff = Staff::query()->create([
            'mda_id' => $this->mda->id, 'staff_number' => fake()->unique()->numerify('MOVE-######'),
            'surname' => 'Officer', 'first_name' => 'Test', 'full_name' => 'Test Officer', 'status' => 'active',
        ]);
        $employment = $staff->employments()->create(['mda_id' => $this->mda->id, 'department_id' => $department?->id, 'is_current' => true]);
        return MovementLine::query()->create(array_merge([
            'workbook_id' => $this->workbook->id, 'staff_id' => $staff->id, 'current_employment_id' => $employment->id,
            'current_salary_scale_id' => $scale->id, 'proposed_salary_scale_id' => $scale->id,
            'current_level' => 1, 'proposed_level' => 1, 'selection_state' => 'included', 'retirement_status' => 'active',
        ], $overrides));
    }
}
