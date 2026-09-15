<?php

namespace Tests\Feature;

use App\Domain\Budget\Models\BudgetWorkbook;
use App\Domain\Movement\Models\MovementLine;
use App\Domain\Movement\Models\MovementWorkbook;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Mda;
use App\Domain\Staff\Models\SalaryScale;
use App\Domain\Staff\Models\Staff;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Dompdf\Dompdf;
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

class BudgetReportExportTest extends TestCase
{
    use RefreshDatabase;

    protected Mda $mda;

    protected User $user;

    protected BudgetWorkbook $workbook;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->mda = Mda::factory()->create();
        $this->user = User::factory()->mdaUser($this->mda, 'report_viewer')->create();
        $this->user->givePermissionTo('view-budgets');
        $this->workbook = $this->workbook($this->mda);
    }

    public function test_excel_separates_department_and_scale_and_preserves_report_figures(): void
    {
        $admin = $this->department('Administration');
        $medical = $this->department('Medical');
        $this->line($this->workbook, $admin, 'GL', 8);
        $this->line($this->workbook, $admin, 'GL', 9);
        $this->line($this->workbook, $admin, 'CH', 8);
        $this->line($this->workbook, $medical, 'CH', 8);
        $previous = $this->workbook($this->mda, 2025);
        $this->line($previous, $admin, 'GL', 8, ['staff_count' => 4, 'proposed_gross_total' => 900.25]);

        // The previous-year lookup must not include another MDA's workbook.
        $hidden = $this->workbook(Mda::factory()->create(), 2025);
        $this->line($hidden, $admin, 'GL', 8, ['staff_count' => 99, 'proposed_gross_total' => 99999]);

        $book = $this->download();
        try {
            $this->assertCount(4, $book->getAllSheets());
            foreach ($book->getAllSheets() as $sheet) {
                // Reading styles changes PhpSpreadsheet's selected range.
                $this->assertSame('A1', $sheet->getSelectedCells());
                $this->assertNull($sheet->getFreezePane());
            }
            $adminGl = $book->getSheetByName('Administration GL');
            $this->assertNotNull($adminGl);
            $this->assertNotNull($book->getSheetByName('Administration CH'));
            $this->assertNotNull($book->getSheetByName('Medical CH'));
            $this->assertSame('2027 Proposed Recurrent Expenditure', $adminGl->getCell('A3')->getValue());
            $this->assertSame("Grade Level\nGRADE LEVEL", $adminGl->getCell('A7')->getValue());
            $this->assertSame("No. of Staff\nRequired\n2027", $adminGl->getCell('F7')->getValue());
            $this->assertEquals([1, 0, 0, 0, 0, 0, 0], $adminGl->rangeToArray('A8:G8', null, true, false)[0]);
            $this->assertEquals([8, 4, 3, 10803, 6003, 2, 14409], $adminGl->rangeToArray('A15:G15', null, true, false)[0]);
            $this->assertEquals(0, $adminGl->getCell('B16')->getValue());
            $this->assertSame(DataType::TYPE_NUMERIC, $adminGl->getCell('B16')->getDataType());
            $this->assertEquals(['TOTAL 1 - 17', 4, 6, 0, 0, 4, 0], $adminGl->rangeToArray('A25:G25', null, true, false)[0]);
            $this->assertEquals(['S/GRADE', null, null, null, null, null, null], $adminGl->rangeToArray('A26:G26', null, true, false)[0]);
            $this->assertEquals(['TOTAL FOR ALL STAFF', null, null, 10803, 12006, null, 28818], $adminGl->rangeToArray('A27:G27', null, true, false)[0]);
            $this->assertEquals(['TOTAL ALLOWANCE FOR ALL STAFF', null, null, null, null, null, null], $adminGl->rangeToArray('A28:G28', null, true, false)[0]);
            $this->assertEquals(['L/GRANT', null, null, null, null, null, null], $adminGl->rangeToArray('A29:G29', null, true, false)[0]);
            $this->assertEquals(['TOTAL PERSONNEL COST', 4, 6, 10803, 12006, 4, 28818], $adminGl->rangeToArray('A30:G30', null, true, false)[0]);
            $this->assertEquals(['Total', 4, 12, 10803, 24012, 8, 57636], $book->getSheetByName('Grand Total')->rangeToArray('A8:G8', null, true, false)[0]);

            foreach ($book->getAllSheets() as $sheet) {
                $this->assertNull($sheet->getCell('A6')->getValue());
                $this->assertSame(48.0, $sheet->getRowDimension(7)->getRowHeight());
                foreach (range('A', 'G') as $column) {
                    $header = $sheet->getStyle($column.'7');
                    $this->assertTrue($header->getFont()->getBold());
                    $this->assertTrue($header->getAlignment()->getWrapText());
                    $this->assertSame(Alignment::HORIZONTAL_CENTER, $header->getAlignment()->getHorizontal());
                    $this->assertSame(Fill::FILL_SOLID, $header->getFill()->getFillType());
                    $this->assertSame(DataType::TYPE_STRING, $sheet->getCell($column.'7')->getDataType());
                }
                $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('G8')->getDataType());
                $this->assertSame('#,##0.00', $sheet->getStyle('G8')->getNumberFormat()->getFormatCode());
                $this->assertSame(PageSetup::ORIENTATION_LANDSCAPE, $sheet->getPageSetup()->getOrientation());
                $this->assertSame(PageSetup::PAPERSIZE_A4, $sheet->getPageSetup()->getPaperSize());
                $this->assertTrue($sheet->getPageSetup()->getFitToPage());
                $this->assertSame(1, $sheet->getPageSetup()->getFitToWidth());
                $this->assertSame(1, $sheet->getPageSetup()->getFitToHeight());
                $this->assertSame('A1:G'.$sheet->getHighestRow(), $sheet->getPageSetup()->getPrintArea());
            }
            $this->assertFalse($adminGl->getStyle('G15')->getFont()->getBold());
            $this->assertSame(Fill::FILL_NONE, $adminGl->getStyle('G15')->getFill()->getFillType());
            $this->assertSame('14,409.00', $adminGl->getCell('G15')->getFormattedValue());
            $this->assertTrue($adminGl->getStyle('G30')->getFont()->getBold());
        } finally {
            $book->disconnectWorksheets();
        }
    }

    public function test_long_or_invalid_department_names_get_distinct_excel_tabs_and_text_stays_literal(): void
    {
        $this->mda->update(['name' => '=1+1']);
        foreach (['Clinical / Administration: North [1]', 'Clinical / Administration: North [2]'] as $name) {
            $this->line($this->workbook, $this->department($name), 'GL', 8);
        }

        $book = $this->download();
        try {
            $titles = $book->getSheetNames();
            $this->assertCount(3, array_unique(array_map('strtolower', $titles)));
            foreach ($titles as $title) {
                $this->assertLessThanOrEqual(31, mb_strlen($title));
                foreach (['\\', '/', '?', '*', ':', '[', ']'] as $invalid) {
                    $this->assertStringNotContainsString($invalid, $title);
                }
            }
            $this->assertStringContainsString('GL', $titles[0]);
            $this->assertStringContainsString('GL (2)', $titles[1]);
            $this->assertSame('=1+1', $book->getSheet(0)->getCell('A2')->getValue());
            $this->assertSame(DataType::TYPE_STRING, $book->getSheet(0)->getCell('A2')->getDataType());
            $this->assertStringContainsString('North [1]', $book->getSheet(0)->getCell('A5')->getValue());
        } finally {
            $book->disconnectWorksheets();
        }
    }

    public function test_allowances_are_shown_under_each_department_scale_without_changing_the_budget_total(): void
    {
        $admin = $this->department('Administration');
        $medical = $this->department('Medical');
        $this->salaryLine($admin, 'GL');
        $this->salaryLine($admin, 'CH');
        $this->salaryLine($medical, 'CH');
        $previous = $this->workbook($this->mda, 2025);
        $this->salaryLine($admin, 'GL', $previous, ['basic_salary' => 80.10, 'total_allowances' => 10.20, 'calculated_gross' => 90.30]);

        $book = $this->download();
        try {
            $adminGl = $book->getSheetByName('Administration GL');
            $this->assertEquals([1, 0, 0, 0, 0, 0, 0], $adminGl->rangeToArray('A8:G8', null, true, false)[0]);
            $this->assertEquals([8, 1, 1, 961.20, 601.50, 1, 1804.80], $adminGl->rangeToArray('A15:G15', null, true, false)[0]);
            $this->assertSame('TOTAL FOR ALL STAFF', $adminGl->getCell('A27')->getValue());
            $this->assertEquals(['TOTAL ALLOWANCE FOR ALL STAFF', null, null, 122.40, 120.60, null, 362.40], $adminGl->rangeToArray('A28:G28', null, true, false)[0]);
            $this->assertEquals(['TOTAL PERSONNEL COST', 1, 1, 1083.60, 722.10, 1, 2167.20], $adminGl->rangeToArray('A30:G30', null, true, false)[0]);
            $this->assertTrue($adminGl->getStyle('A28')->getAlignment()->getWrapText());
            $this->assertTrue($adminGl->getStyle('G28')->getFont()->getBold());
            foreach (['Administration CH', 'Medical CH'] as $title) {
                $sheet = $book->getSheetByName($title);
                $this->assertEquals(1804.80, $sheet->getCell('G15')->getValue());
                $this->assertEquals(['TOTAL ALLOWANCE FOR ALL STAFF', null, null, 0, 120.60, null, 362.40], $sheet->rangeToArray('A26:G26', null, true, false)[0]);
                $this->assertEquals(['TOTAL PERSONNEL COST', 0, 1, 0, 722.10, 1, 2167.20], $sheet->rangeToArray('A28:G28', null, true, false)[0]);
            }
            $this->assertEquals(['Total', 1, 3, 1083.60, 2166.30, 3, 6501.60], $book->getSheetByName('Grand Total')->rangeToArray('A8:G8', null, true, false)[0]);
        } finally {
            $book->disconnectWorksheets();
        }
        $html = $this->actingAs($this->user)->get($this->url())->assertOk()->getContent();
        $this->assertSame(3, substr_count($html, 'TOTAL ALLOWANCE FOR ALL STAFF'));
        $this->assertSame(3, substr_count($html, 'TOTAL PERSONNEL COST'));
        $this->assertSame(3, substr_count($html, '362.40'));
    }

    public function test_allowances_stay_with_their_department_when_only_other_departments_have_staff(): void
    {
        $this->salaryLine($this->department('Medical'), 'CH');
        $book = $this->download();
        try {
            $this->assertSame(['Medical CH', 'Grand Total'], $book->getSheetNames());
            $medical = $book->getSheetByName('Medical CH');
            $this->assertEquals(['TOTAL ALLOWANCE FOR ALL STAFF', null, null, 0, 120.60, null, 362.40], $medical->rangeToArray('A26:G26', null, true, false)[0]);
            $this->assertEquals(2167.20, $book->getSheetByName('Grand Total')->getCell('G8')->getValue());
        } finally {
            $book->disconnectWorksheets();
        }
    }

    public function test_moved_staff_allowances_follow_the_required_grade_level_summary(): void
    {
        $admin = $this->department('Administration');
        $current = ['basic_salary' => 100, 'total_allowances' => 20, 'calculated_gross' => 120];
        $proposed = ['basic_salary' => 200, 'total_allowances' => 50, 'calculated_gross' => 250];

        $this->reportStaff($admin, 'GL', lineAttributes: [
            'current_level' => 8,
            'proposed_level' => 9,
            'current_amounts' => $current,
            'proposed_amounts' => $proposed,
        ]);
        $this->line($this->workbook, $admin, 'GL', 8, [
            'staff_count' => 1,
            'retiring_count' => 0,
            'required_staff_count' => 0,
            'current_gross_total' => $current['calculated_gross'],
            'proposed_gross_total' => 0,
        ]);
        $this->line($this->workbook, $admin, 'GL', 9, [
            'staff_count' => 0,
            'retiring_count' => 0,
            'required_staff_count' => 1,
            'current_gross_total' => 0,
            'proposed_gross_total' => $proposed['calculated_gross'],
        ]);

        $book = $this->download();
        try {
            $sheet = $book->getSheetByName('Administration GL');

            $this->assertEquals([8, 0, 1, 0, 600, 0, 0], $sheet->rangeToArray('A15:G15', null, true, false)[0]);
            $this->assertEquals([9, 0, 0, 0, 0, 1, 2400], $sheet->rangeToArray('A16:G16', null, true, false)[0]);
            $this->assertEquals(['TOTAL ALLOWANCE FOR ALL STAFF', null, null, 0, 120, null, 600], $sheet->rangeToArray('A28:G28', null, true, false)[0]);
            $this->assertEquals(['TOTAL PERSONNEL COST', 0, 1, 0, 720, 1, 3000], $sheet->rangeToArray('A30:G30', null, true, false)[0]);
        } finally {
            $book->disconnectWorksheets();
        }
    }

    public function test_changed_movement_amounts_keep_matching_allowance_splits_without_rewriting_budget(): void
    {
        $line = $this->salaryLine($this->department('Administration'), 'GL');
        $line->update(['proposed_amounts' => ['basic_salary' => 999, 'total_allowances' => 100, 'calculated_gross' => 1099]]);
        $book = $this->download();
        try {
            $sheet = $book->getSheet(0);
            $this->assertEquals(2167.20, $sheet->getCell('G15')->getValue());
            $this->assertEquals(['TOTAL ALLOWANCE FOR ALL STAFF', null, null, 0, 120.60, null, 0], $sheet->rangeToArray('A28:G28', null, true, false)[0]);
            $this->assertStringNotContainsString('Allowances remain included', json_encode($sheet->toArray()));
        } finally {
            $book->disconnectWorksheets();
        }
    }

    public function test_print_report_starts_each_group_and_grand_total_on_a_landscape_page(): void
    {
        $admin = $this->department('Administration');
        foreach (range(1, 17) as $level) {
            $this->line($this->workbook, $admin, 'GL', $level);
        }
        $this->line($this->workbook, $admin, 'CH', 8);
        $this->line($this->workbook, $this->department('Medical'), 'CM', 4);

        $html = $this->actingAs($this->user)->get($this->url())->assertOk()
            ->assertSee('Export to Excel')->assertSee($this->url().'/export', false)->getContent();
        $this->assertSame(4, substr_count($html, '<section class="report-page">'));
        $this->assertSame(4, substr_count($html, '<p>Government of Niger State</p>'));

        $pdf = new Dompdf(['defaultMediaType' => 'print', 'isRemoteEnabled' => false]);
        $pdf->loadHtml($html);
        $pdf->render();
        $canvas = $pdf->getCanvas();
        $this->assertSame(4, $canvas->get_page_count());
        $this->assertGreaterThan($canvas->get_height(), $canvas->get_width());
    }

    public function test_export_requires_budget_view_permission_approval_and_mda_access(): void
    {
        $this->actingAs($this->user);
        foreach (['draft', 'submitted', 'rejected'] as $status) {
            $this->workbook->update(['status' => $status]);
            foreach (['recurrent-expenditure', 'staff-list', 'qualification-distribution', 'manpower-distribution'] as $report) {
                $this->get($this->url($report).'/export')->assertForbidden();
            }
        }
        $this->workbook->update(['status' => 'approved']);
        $other = $this->workbook(Mda::factory()->create());
        foreach (['recurrent-expenditure', 'staff-list', 'qualification-distribution', 'manpower-distribution'] as $report) {
            $this->get("/api/budget-workbooks/{$other->id}/reports/{$report}/export")->assertForbidden();
        }
        $this->get("/api/budget-workbooks/{$this->workbook->id}/reports/unknown/export")->assertNotFound();
        $this->user->revokePermissionTo('view-budgets');
        foreach (['recurrent-expenditure', 'staff-list', 'qualification-distribution', 'manpower-distribution'] as $report) {
            $this->get($this->url($report).'/export')->assertForbidden();
        }
    }

    public function test_locked_empty_workbook_exports_a_zero_summary(): void
    {
        $this->workbook->update(['status' => 'locked']);
        $book = $this->download();
        try {
            $this->assertSame(['Grand Total'], $book->getSheetNames());
            $this->assertEquals(['Total', 0, 0, 0, 0, 0, 0], $book->getActiveSheet()->rangeToArray('A8:G8', null, true, false)[0]);
        } finally {
            $book->disconnectWorksheets();
        }
    }

    public function test_budget_staff_list_exports_every_department_with_real_dates_and_literal_identifiers(): void
    {
        $admin = $this->department('Administration');
        $medical = $this->department('Medical');
        $namedQualification = $this->reportStaff($admin, qualification: 'ND(HIM)', staffAttributes: ['legacy_cno' => '000123456789012345', 'legacy_psn' => '000009876543210', 'full_name' => '=1+1']);
        $namedQualification->staff->qualifications()->update(['highest_qualification_name' => 'OND']);
        $this->reportStaff($admin, staffAttributes: ['full_name' => 'Already Retired'], lineAttributes: ['retirement_status' => 'retired']);
        $this->reportStaff($admin, staffAttributes: ['full_name' => 'Contract Retired'], lineAttributes: [
            'retirement_status' => 'retired',
            'is_contract_staff' => true,
        ]);
        $this->reportStaff($medical);
        $hidden = $this->workbook(Mda::factory()->create());
        $this->reportStaff($medical, workbook: $hidden, staffAttributes: ['full_name' => 'HIDDEN STAFF']);

        $book = $this->download('staff-list');
        try {
            $this->assertSame(['Administration', 'Medical'], $book->getSheetNames());
            $sheet = $book->getSheetByName('Administration');
            $this->assertSame(10, $sheet->getHighestRow());
            $this->assertSame('2027 Budget Staff List', $sheet->getCell('A3')->getValue());
            $this->assertSame('GL 8 (2)', $sheet->getCell('A7')->getValue());
            $this->assertSame('DFA', $sheet->getCell('G8')->getValue());
            $exported = $sheet->rangeToArray('A9:N10', null, true, false);
            $this->assertSame([1, 2], array_column($exported, 0));
            $this->assertNotContains('Already Retired', array_column($exported, 1));
            $this->assertContains('Contract Retired', array_column($exported, 1));
            $specialRow = array_search('000123456789012345', array_column($exported, 12), true) + 9;
            $this->assertSame('=1+1', $sheet->getCell('B'.$specialRow)->getValue());
            $this->assertSame('ND(HIM)', $sheet->getCell('F'.$specialRow)->getValue());
            $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('B'.$specialRow)->getDataType());
            $this->assertSame('000009876543210', $sheet->getCell('K'.$specialRow)->getValue());
            $this->assertSame('00009999999999999', $sheet->getCell('L'.$specialRow)->getValue());
            $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('M'.$specialRow)->getDataType());
            $this->assertSame('03-02-1990', $sheet->getCell('D'.$specialRow)->getFormattedValue());
            $this->assertSame('01-06-2015', $sheet->getCell('G'.$specialRow)->getFormattedValue());
            $this->assertSame('01-01-2023', $sheet->getCell('H'.$specialRow)->getFormattedValue());
            $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('G'.$specialRow)->getDataType());
            $this->assertSame('A8:N10', $sheet->getAutoFilter()->getRange());
            foreach ($book->getAllSheets() as $departmentSheet) {
                $this->assertNull($departmentSheet->getFreezePane());
                $this->assertTrue($departmentSheet->getStyle('N8')->getAlignment()->getWrapText());
                $this->assertSame(PageSetup::ORIENTATION_LANDSCAPE, $departmentSheet->getPageSetup()->getOrientation());
                $this->assertSame(0, $departmentSheet->getPageSetup()->getFitToHeight());
                $this->assertSame(['1', '8'], $departmentSheet->getPageSetup()->getRowsToRepeatAtTop());
                $this->assertStringNotContainsString('HIDDEN STAFF', json_encode($departmentSheet->toArray()));
            }
        } finally {
            $book->disconnectWorksheets();
        }
        $print = $this->get($this->url('staff-list'))->assertOk()->assertSee('Export to Excel')
            ->assertSee('GL 8 (2)')
            ->assertSee('ND(HIM)')
            ->assertSee('Contract Retired')
            ->assertDontSee('Already Retired')
            ->assertSee('03-02-1990')->assertSee('01-06-2015')->assertSee('01-01-2023')
            ->assertDontSee('1990-02-03')->assertDontSee('2015-06-01')->assertDontSee('2023-01-01')
            ->assertSee($this->url('staff-list').'/export', false);
        $this->assertStringContainsString('<tr class="staff-section-row">', $print->getContent());
        $this->assertStringContainsString('<th colspan="14">GL 8 (2)</th>', $print->getContent());
    }

    public function test_budget_staff_list_formats_staff_names_consistently(): void
    {
        $admin = $this->department('Administration');
        $this->reportStaff($admin, staffAttributes: ['full_name' => 'BABANGIDA AHMED']);
        $this->reportStaff($admin, staffAttributes: ['full_name' => 'A NDALIMAN ISHAKU']);
        $this->reportStaff($admin, staffAttributes: ['full_name' => 'jibrin a. mohammed']);

        $book = $this->download('staff-list');
        try {
            $names = array_column($book->getActiveSheet()->rangeToArray('B9:B11', null, true, false), 0);

            $this->assertSame(['A Ndaliman Ishaku', 'Babangida Ahmed', 'Jibrin A. Mohammed'], $names);
        } finally {
            $book->disconnectWorksheets();
        }

        $this->get($this->url('staff-list'))->assertOk()
            ->assertSee('Babangida Ahmed')
            ->assertSee('A Ndaliman Ishaku')
            ->assertSee('Jibrin A. Mohammed')
            ->assertDontSee('BABANGIDA AHMED');
    }

    public function test_budget_staff_list_includes_staff_contracts_and_restarts_serial_numbers_by_department(): void
    {
        $admin = $this->department('Administration');
        $medical = $this->department('Medical');
        $this->reportStaff($admin, staffAttributes: ['full_name' => 'ZULU ADMIN']);
        $this->reportStaff($admin, staffAttributes: ['full_name' => 'CONTRACT ADMIN', 'is_contract_staff' => true], lineAttributes: [
            'retirement_status' => 'retired',
            'eligibility_status' => 'retired',
            'is_contract_staff' => false,
        ]);
        $this->reportStaff($medical, staffAttributes: ['full_name' => 'MEDICAL OFFICER']);

        $book = $this->download('staff-list');
        try {
            $adminSheet = $book->getSheetByName('Administration');
            $medicalSheet = $book->getSheetByName('Medical');
            $adminRows = $adminSheet->rangeToArray('A9:N10', null, true, false);

            $this->assertSame([1, 2], array_column($adminRows, 0));
            $this->assertSame(['Contract Admin', 'Zulu Admin'], array_column($adminRows, 1));
            $this->assertSame('Contract / Retired', $adminRows[0][13]);
            $this->assertSame(1, $medicalSheet->getCell('A9')->getValue());
        } finally {
            $book->disconnectWorksheets();
        }

        $this->get($this->url('staff-list'))->assertOk()
            ->assertSee('Contract Admin')
            ->assertSee('Contract / Retired')
            ->assertSee('Medical Officer');
    }

    public function test_budget_and_movement_reports_fall_back_to_staff_number_for_all_blank_cnos(): void
    {
        $department = $this->department('Administration');
        $this->workbook->movementWorkbook->update(['budget_minimum_step' => 6]);
        foreach ([null, '', '   ', '00098765'] as $index => $cno) {
            $line = $this->reportStaff($department, staffAttributes: [
                'staff_number' => '0001234'.$index, 'legacy_cno' => $cno, 'full_name' => 'Officer '.$index,
            ]);
            $line->currentEmployment->update(['next_promotion_date' => '2026-01-01']);
        }
        $expected = ['00012340', '00012341', '00012342', '00098765'];

        $book = $this->download('staff-list');
        try {
            $sheet = $book->getActiveSheet();
            $this->assertSame('GL 8 (4)', $sheet->getCell('A7')->getValue());
            $this->assertSame($expected, array_column($sheet->rangeToArray('M9:M12'), 0));
        } finally {
            $book->disconnectWorksheets();
        }
        $print = $this->get($this->url('staff-list'))->assertOk();
        foreach ($expected as $cno) {
            $print->assertSee('<td>'.$cno.'</td>', false);
        }

        $this->user->givePermissionTo('view-movement-sheets');
        $response = $this->get('/api/movement-workbooks/'.$this->workbook->movement_workbook_id.'/detail-export')->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();
        try {
            Cell::setValueBinder(new DefaultValueBinder);
            $book = IOFactory::load($path);
            $sheet = $book->getActiveSheet();
            $this->assertSame($expected, array_column($sheet->rangeToArray('B5:B8'), 0));
            $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('B5')->getDataType());
            $this->assertSame('6', $sheet->getCell('F2')->getFormattedValue());
            foreach (['F5' => '01-06-2015', 'G5' => '01-01-2023', 'H5' => '01-01-2026'] as $cell => $date) {
                $this->assertSame($date, $sheet->getCell($cell)->getFormattedValue());
                $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell($cell)->getDataType());
            }
            $book->disconnectWorksheets();
        } finally {
            unlink($path);
        }
    }

    public function test_qualification_export_keeps_scales_in_department_sheets_and_totals_male_and_female_counts(): void
    {
        $medical = $this->department('Medical');
        $admin = $this->department('Administration');
        $this->reportStaff($medical, 'CM', 'OND', lineAttributes: ['proposed_level' => 3]);
        $this->reportStaff($medical, 'CM', 'OND', ['sex' => 'Female'], ['proposed_level' => 3]);
        $this->reportStaff($medical, 'CM', 'HND', lineAttributes: ['proposed_level' => 4]);
        $this->reportStaff($medical, 'CM', 'HND', lineAttributes: ['proposed_level' => 4, 'retirement_status' => 'retiring']);
        $this->reportStaff($medical, 'CM', 'HND', lineAttributes: ['proposed_level' => 4, 'retirement_status' => 'retired']);
        $this->reportStaff($medical, 'CH', 'HND', ['sex' => 'F']);
        $this->reportStaff($admin, 'GL');
        $hidden = $this->workbook(Mda::factory()->create());
        $this->reportStaff($medical, 'CM', 'HND', workbook: $hidden);

        $book = $this->download('qualification-distribution');
        try {
            $this->assertCount(2, $book->getAllSheets());
            $sheet = $book->getSheetByName('Medical');
            $this->assertSame('Salary scale: CM', $sheet->getCell('A7')->getValue());
            $this->assertSame('HND', $sheet->getCell('B8')->getValue());
            $this->assertSame('OND', $sheet->getCell('D8')->getValue());
            $this->assertContains('B8:C8', $sheet->getMergeCells());
            $this->assertSame(['Male', 'Female', 'Male', 'Female'], $sheet->rangeToArray('B9:E9')[0]);
            $this->assertEquals([3, 0, 0, 1, 1, 2], $sheet->rangeToArray('A10:F10', null, true, false)[0]);
            $this->assertEquals([4, 1, 0, 0, 0, 1], $sheet->rangeToArray('A11:F11', null, true, false)[0]);
            $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('B10')->getDataType());
            $this->assertSame('=SUM(F10:F11)', $sheet->getCell('F12')->getValue());
            $this->assertEquals(3, $sheet->getCell('F12')->getCalculatedValue());
            $this->assertSame('Salary scale: CH', $sheet->getCell('A14')->getValue());
            $this->assertEquals([8, 0, 1, 0, 0, 1], $sheet->rangeToArray('A17:F17', null, true, false)[0]);
            $this->assertEquals(1, $sheet->getCell('F18')->getCalculatedValue());
            $this->assertArrayHasKey('A13', $sheet->getBreaks());
            $this->assertTrue($sheet->getStyle('B8')->getAlignment()->getWrapText());
            $this->assertTrue($sheet->getStyle('F12')->getFont()->getBold());
            $this->assertSame(['1', '5'], $sheet->getPageSetup()->getRowsToRepeatAtTop());
            $this->assertNull($sheet->getFreezePane());
            $this->assertSame(PageSetup::ORIENTATION_LANDSCAPE, $sheet->getPageSetup()->getOrientation());
        } finally {
            $book->disconnectWorksheets();
        }
        $this->get($this->url('qualification-distribution'))->assertOk()->assertSee('Export to Excel')
            ->assertSee($this->url('qualification-distribution').'/export', false);
    }

    public function test_manpower_distribution_exports_staff_by_sex_level_and_professionalism(): void
    {
        $nursing = $this->department('Nursing');
        $this->reportStaff($nursing, 'CH', staffAttributes: ['sex' => 'M'], lineAttributes: ['proposed_level' => 3])
            ->currentEmployment->update(['staff_category' => 'Professional']);
        $this->reportStaff($nursing, 'CH', staffAttributes: ['sex' => 'Female'], lineAttributes: ['proposed_level' => 3])
            ->currentEmployment->update(['staff_category' => 'Professional']);
        $this->reportStaff($nursing, 'CH', staffAttributes: ['sex' => 'F'], lineAttributes: ['proposed_level' => 4])
            ->currentEmployment->update(['staff_category' => 'Administrative']);
        $this->reportStaff($nursing, 'CH', staffAttributes: ['sex' => 'M'], lineAttributes: ['proposed_level' => 6])
            ->currentEmployment->update(['staff_category' => 'Hospital Attendant']);
        $this->reportStaff($nursing, 'CH', staffAttributes: ['sex' => 'F'], lineAttributes: ['proposed_level' => 7, 'retirement_status' => 'retiring'])
            ->currentEmployment->update(['staff_category' => 'Clerical']);
        $hidden = $this->workbook(Mda::factory()->create());
        $this->reportStaff($nursing, 'CH', staffAttributes: ['sex' => 'F'], workbook: $hidden);

        $book = $this->download('manpower-distribution');
        try {
            $this->assertSame(['Nursing'], $book->getSheetNames());
            $sheet = $book->getActiveSheet();
            $this->assertSame('2027 Manpower Distribution', $sheet->getCell('A3')->getValue());
            $this->assertSame('NO. OF STAFF BY SEX', $sheet->getCell('A7')->getValue());
            $this->assertSame('CH', $sheet->getCell('A8')->getValue());
            $this->assertSame('NO. OF STAFF', $sheet->getCell('B8')->getValue());
            $this->assertContains('B8:D8', $sheet->getMergeCells());
            $this->assertEquals(['CH1', 0, 0, 0], $sheet->rangeToArray('A10:D10', null, true, false)[0]);
            $this->assertEquals(['CH3', 1, 1, 2], $sheet->rangeToArray('A12:D12', null, true, false)[0]);
            $this->assertEquals(['CH4', 0, 1, 1], $sheet->rangeToArray('A13:D13', null, true, false)[0]);
            $this->assertEquals(['CH6', 1, 0, 1], $sheet->rangeToArray('A15:D15', null, true, false)[0]);
            $this->assertSame('S/GRADE', $sheet->getCell('A25')->getValue());
            $this->assertSame('G/TOTAL', $sheet->getCell('A26')->getValue());
            $this->assertSame('=SUM(D10:D24)', $sheet->getCell('D26')->getValue());
            $this->assertEquals(4, $sheet->getCell('D26')->getCalculatedValue());
            $this->assertSame('STAFF STRENGTH BY PROFESSIONALISM', $sheet->getCell('A28')->getValue());
            $this->assertEquals(['Professional/Technicians', 1, 1, 2], $sheet->rangeToArray('A31:D31', null, true, false)[0]);
            $this->assertEquals(['Administrative/Managerial', 0, 1, 1], $sheet->rangeToArray('A32:D32', null, true, false)[0]);
            $this->assertEquals(['Clerical', 0, 0, 0], $sheet->rangeToArray('A33:D33', null, true, false)[0]);
            $this->assertEquals(['Others', 1, 0, 1], $sheet->rangeToArray('A34:D34', null, true, false)[0]);
            $this->assertSame('=SUM(D31:D34)', $sheet->getCell('D35')->getValue());
            $this->assertEquals(4, $sheet->getCell('D35')->getCalculatedValue());
            $this->assertNull($sheet->getFreezePane());
            $this->assertSame(PageSetup::ORIENTATION_LANDSCAPE, $sheet->getPageSetup()->getOrientation());
        } finally {
            $book->disconnectWorksheets();
        }

        $this->get($this->url('manpower-distribution'))->assertOk()
            ->assertSee('Export to Excel')
            ->assertSee('No. of Staff by Sex')
            ->assertSee('Staff Strength by Professionalism')
            ->assertSee('CH3')
            ->assertSee('Professional/Technicians')
            ->assertSee($this->url('manpower-distribution').'/export', false);
    }

    public function test_supporting_reports_handle_department_tab_name_collisions_and_unassigned_staff(): void
    {
        $this->reportStaff($this->department('Clinical / Administration: North [1]'));
        $this->reportStaff($this->department('Clinical / Administration: North [2]'));
        $this->reportStaff(null);
        foreach (['staff-list', 'qualification-distribution', 'manpower-distribution'] as $report) {
            $book = $this->download($report);
            try {
                $titles = $book->getSheetNames();
                $this->assertCount(3, array_unique(array_map('strtolower', $titles)));
                $this->assertContains('Unassigned', $titles);
                foreach ($titles as $title) {
                    $this->assertLessThanOrEqual(31, mb_strlen($title));
                    $this->assertStringNotContainsString('/', $title);
                }
            } finally {
                $book->disconnectWorksheets();
            }
        }
    }

    public function test_locked_supporting_reports_with_no_rows_have_a_readable_empty_sheet(): void
    {
        $this->workbook->update(['status' => 'locked']);
        foreach (['staff-list', 'qualification-distribution', 'manpower-distribution'] as $report) {
            $book = $this->download($report);
            try {
                $this->assertSame(['No records'], $book->getSheetNames());
                $messageCell = 'A7';
                $this->assertSame('No staff match this report.', $book->getActiveSheet()->getCell($messageCell)->getValue());
            } finally {
                $book->disconnectWorksheets();
            }
        }
    }

    protected function download(string $report = 'recurrent-expenditure'): Spreadsheet
    {
        $response = $this->actingAs($this->user)->get($this->url($report).'/export')->assertOk()
            ->assertDownload("budget-{$this->workbook->id}-{$report}.xlsx");
        $path = $response->baseResponse->getFile()->getPathname();
        try {
            Cell::setValueBinder(new DefaultValueBinder);

            return IOFactory::load($path);
        } finally {
            unlink($path);
        }
    }

    protected function url(string $report = 'recurrent-expenditure'): string
    {
        return "/api/budget-workbooks/{$this->workbook->id}/reports/{$report}";
    }

    protected function workbook(Mda $mda, int $year = 2026): BudgetWorkbook
    {
        $movement = MovementWorkbook::query()->create([
            'mda_id' => $mda->id, 'year' => $year, 'budget_year' => $year + 1, 'status' => 'approved',
        ]);

        return BudgetWorkbook::query()->create([
            'mda_id' => $mda->id, 'movement_workbook_id' => $movement->id, 'year' => $year, 'status' => 'approved',
        ]);
    }

    protected function department(string $name): Department
    {
        return Department::query()->create(['mda_id' => $this->mda->id, 'code' => fake()->unique()->lexify('???'), 'name' => $name]);
    }

    protected function line(BudgetWorkbook $workbook, Department $department, string $scaleCode, int $level, array $overrides = []): void
    {
        $scale = SalaryScale::query()->firstOrCreate(['code' => $scaleCode], [
            'name' => $scaleCode, 'min_level' => 1, 'max_level' => 17, 'min_step' => 1, 'max_step' => 15, 'status' => 'active',
        ]);
        $workbook->lines()->create(array_merge([
            'department_id' => $department->id, 'salary_scale_id' => $scale->id, 'level' => $level,
            'staff_count' => 3, 'retiring_count' => 1, 'current_gross_total' => 1000.50, 'proposed_gross_total' => 1200.75,
        ], $overrides));
    }

    protected function reportStaff(?Department $department, string $scaleCode = 'GL', string $qualification = 'OND', array $staffAttributes = [], array $lineAttributes = [], ?BudgetWorkbook $workbook = null): MovementLine
    {
        $workbook ??= $this->workbook;
        $staff = Staff::query()->create(array_merge([
            'mda_id' => $workbook->mda_id, 'staff_number' => fake()->unique()->numerify('STAFF-########'),
            'legacy_cno' => fake()->unique()->numerify('C######'), 'legacy_psn' => '000456',
            'surname' => 'Officer', 'first_name' => 'Test', 'full_name' => 'Test Officer', 'sex' => 'M',
            'status' => 'active', 'date_of_birth' => '1990-02-03',
        ], $staffAttributes));
        $staff->personalDetail()->create(['file_no' => '00009999999999999', 'lga' => 'Chanchaga']);
        $staff->qualifications()->create(['qualification_name' => $qualification, 'is_highest' => true]);
        $employment = $staff->employments()->create([
            'mda_id' => $workbook->mda_id, 'department_id' => $department?->id,
            'date_first_appointment' => '2015-06-01', 'date_last_promotion' => '2023-01-01', 'is_current' => true,
        ]);
        $scale = SalaryScale::query()->firstOrCreate(['code' => $scaleCode], [
            'name' => $scaleCode, 'min_level' => 1, 'max_level' => 17, 'min_step' => 1, 'max_step' => 15, 'status' => 'active',
        ]);

        return MovementLine::query()->create(array_merge([
            'workbook_id' => $workbook->movement_workbook_id, 'staff_id' => $staff->id, 'current_employment_id' => $employment->id,
            'current_salary_scale_id' => $scale->id, 'proposed_salary_scale_id' => $scale->id,
            'current_level' => 8, 'current_step' => 2, 'proposed_level' => 8, 'proposed_step' => 3,
            'selection_state' => 'included', 'eligibility_status' => 'due', 'retirement_status' => 'active',
        ], $lineAttributes));
    }

    protected function salaryLine(Department $department, string $scale, ?BudgetWorkbook $workbook = null, ?array $previousAmounts = null): MovementLine
    {
        $workbook ??= $this->workbook;
        $current = $previousAmounts ?? ['basic_salary' => 100.25, 'total_allowances' => 20.10, 'calculated_gross' => 120.35];
        $proposed = $previousAmounts ?? ['basic_salary' => 150.40, 'total_allowances' => 30.20, 'calculated_gross' => 180.60];
        $movement = $this->reportStaff($department, $scale, lineAttributes: ['current_amounts' => $current, 'proposed_amounts' => $proposed], workbook: $workbook);
        $this->line($workbook, $department, $scale, 8, [
            'staff_count' => 1, 'retiring_count' => 0, 'current_gross_total' => $current['calculated_gross'], 'proposed_gross_total' => $proposed['calculated_gross'],
        ]);

        return $movement;
    }
}
