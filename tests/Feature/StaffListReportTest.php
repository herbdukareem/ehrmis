<?php

namespace Tests\Feature;

use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Mda;
use App\Domain\Organization\Models\Station;
use App\Domain\Staff\Models\AllowanceType;
use App\Domain\Staff\Models\Cadre;
use App\Domain\Staff\Models\SalaryScale;
use App\Domain\Staff\Models\SalaryStructureRate;
use App\Domain\Staff\Models\Staff;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class StaffListReportTest extends TestCase
{
    use RefreshDatabase;

    protected Mda $mda;

    protected User $user;

    protected Department $department;

    protected Station $station;

    protected SalaryScale $scale;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 9, 10)->startOfDay());
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->mda = Mda::factory()->create();
        $this->user = User::factory()->mdaUser($this->mda, 'report_viewer')->create();
        $this->user->givePermissionTo(['view-reports', 'export-reports']);
        $this->department = Department::query()->create(['mda_id' => $this->mda->id, 'code' => 'RPT', 'name' => 'Report department', 'status' => 'active']);
        $this->station = Station::query()->create(['mda_id' => $this->mda->id, 'code' => 'RPT', 'name' => 'Report station', 'status' => 'active']);
        $this->scale = SalaryScale::query()->create(['code' => 'RPT', 'name' => 'Report scale', 'status' => 'active']);
    }

    public function test_report_shows_basic_information_current_appointment_and_effective_allowances(): void
    {
        $staff = $this->staff();
        $hazard = AllowanceType::query()->firstOrCreate(['code' => 'report_hazard'], ['name' => 'Report hazard', 'status' => 'active']);
        $call = AllowanceType::query()->firstOrCreate(['code' => 'report_call'], ['name' => 'Report call', 'status' => 'active']);
        $rate = SalaryStructureRate::query()->create(['salary_scale_id' => $this->scale->id, 'level' => 6, 'step' => 2, 'basic_salary' => 100000]);
        $rate->rateAllowances()->create(['allowance_type_id' => $hazard->id, 'amount' => 12000]);
        $rate->rateAllowances()->create(['allowance_type_id' => $call->id, 'amount' => 5000]);
        $staff->allowanceAssignments()->create(['allowance_type_id' => $hazard->id, 'source' => 'legacy_import', 'is_eligible' => true]);
        $staff->allowanceAssignments()->create(['allowance_type_id' => $hazard->id, 'source' => 'staff_management', 'is_eligible' => false, 'effective_from' => '2026-09-10', 'effective_to' => '2026-09-10']);
        $staff->allowanceAssignments()->create(['allowance_type_id' => $hazard->id, 'source' => 'scheduled_change', 'is_eligible' => true, 'effective_from' => '2026-09-11']);
        $staff->allowanceAssignments()->create(['allowance_type_id' => $call->id, 'source' => 'legacy_import', 'is_eligible' => true]);
        $staff->allowanceAssignments()->create(['allowance_type_id' => $call->id, 'source' => 'staff_management', 'is_eligible' => false, 'effective_to' => '2026-09-09']);
        $staff->employments()->create(['mda_id' => $this->mda->id, 'is_current' => false, 'initial_rank' => 'Obsolete rank']);

        $response = $this->actingAs($this->user)->getJson('/api/reports/staff-list')->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.per_page', 500)
            ->assertJsonPath('data.0.staff_number', '000123')
            ->assertJsonPath('data.0.psn', '000456')
            ->assertJsonPath('data.0.phone', '08012345678')
            ->assertJsonPath('data.0.date_first_appointment', '2015-06-01')
            ->assertJsonPath('data.0.initial_rank', 'Entry rank')
            ->assertJsonPath('data.0.department', 'Report department')
            ->assertJsonPath('data.0.station', 'Report station');
        $this->assertSame('Full name', collect($response->json('columns'))->keyBy('key')['full_name']['label']);
        $this->assertEquals(5000, $response->json('data.0.allowance_total'));
        $this->assertEquals(105000, $response->json('data.0.gross_salary'));
        $this->assertSame([], $response->json('data.0.unpriced_allowances'));
        $allowances = collect($response->json('data.0.allowances'))->keyBy('id');
        $this->assertSame('Not eligible', $allowances[$hazard->id]['eligibility']);
        $this->assertEquals(0, $allowances[$hazard->id]['amount']);
        $this->assertSame('Eligible', $allowances[$call->id]['eligibility']);
        $this->assertEquals(5000, $allowances[$call->id]['amount']);
    }

    public function test_missing_rate_and_missing_assignment_are_not_presented_as_zero_payments(): void
    {
        $staff = $this->staff();
        $type = AllowanceType::query()->firstOrCreate(['code' => 'report_missing'], ['name' => 'Missing rate', 'status' => 'active']);
        $unassigned = AllowanceType::query()->firstOrCreate(['code' => 'report_unassigned'], ['name' => 'Unassigned', 'status' => 'active']);
        $staff->allowanceAssignments()->create(['allowance_type_id' => $type->id, 'is_eligible' => true, 'source' => 'staff_management']);
        $response = $this->actingAs($this->user)->getJson('/api/reports/staff-list')->assertOk()
            ->assertJsonPath('data.0.basic_salary', null)
            ->assertJsonPath('data.0.allowance_total', null)
            ->assertJsonPath('data.0.gross_salary', null)
            ->assertJsonPath('data.0.unpriced_allowances', ['Missing rate']);
        $allowances = collect($response->json('data.0.allowances'))->keyBy('id');
        $this->assertSame('Eligible', $allowances[$type->id]['eligibility']);
        $this->assertNull($allowances[$type->id]['amount']);
        $this->assertSame('Not assigned', $allowances[$unassigned->id]['eligibility']);
        $this->assertNull($allowances[$unassigned->id]['amount']);
    }

    public function test_preview_and_excel_keep_basic_and_hazard_in_totals_when_another_allowance_is_unpriced(): void
    {
        $staff = $this->staff();
        $hazard = AllowanceType::query()->create(['code' => 'report_hazard', 'name' => 'Report hazard', 'status' => 'active']);
        $missing = AllowanceType::query()->create(['code' => 'report_missing', 'name' => 'Unpriced call', 'status' => 'active']);
        $excluded = AllowanceType::query()->create(['code' => 'report_excluded', 'name' => 'Excluded allowance', 'status' => 'active']);
        $rate = SalaryStructureRate::query()->create(['salary_scale_id' => $this->scale->id, 'level' => 6, 'step' => 2, 'basic_salary' => 63135.30]);
        $rate->rateAllowances()->create(['allowance_type_id' => $hazard->id, 'amount' => 5000]);
        $rate->rateAllowances()->create(['allowance_type_id' => $excluded->id, 'amount' => 12000]);
        foreach ([$hazard, $missing, $excluded] as $type) {
            $staff->allowanceAssignments()->create(['allowance_type_id' => $type->id, 'is_eligible' => $type->id !== $excluded->id, 'source' => 'staff_management']);
        }

        $response = $this->actingAs($this->user)->getJson('/api/reports/staff-list')->assertOk()
            ->assertJsonPath('data.0.unpriced_allowances', ['Unpriced call']);
        $this->assertEquals(63135.30, $response->json('data.0.basic_salary'));
        $this->assertEquals(5000, $response->json('data.0.allowance_total'));
        $this->assertEquals(68135.30, $response->json('data.0.gross_salary'));
        $allowances = collect($response->json('data.0.allowances'))->keyBy('id');
        $this->assertNull($allowances[$missing->id]['amount']);
        $this->assertEquals(0, $allowances[$excluded->id]['amount']);

        $export = $this->get('/api/reports/staff-list/export')->assertOk();
        $path = $export->baseResponse->getFile()->getPathname();
        try {
            // Read as a fresh Excel client: the export's string binder is process-global.
            Cell::setValueBinder(new DefaultValueBinder);
            $book = IOFactory::load($path);
            $sheet = $book->getActiveSheet();
            $this->assertEquals(63135.30, $sheet->getCell('AA2')->getValue());
            $this->assertEquals(5000, $sheet->getCell('AB2')->getValue());
            $this->assertEquals(68135.30, $sheet->getCell('AC2')->getValue());
            $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('AC2')->getDataType());
            $this->assertSame('#,##0.00', $sheet->getStyle('AC2')->getNumberFormat()->getFormatCode());
            $lastColumn = $sheet->getHighestColumn();
            $this->assertSame('Calculation note', $sheet->getCell($lastColumn.'1')->getValue());
            $this->assertSame('Partial total; excludes unpriced allowances: Unpriced call', $sheet->getCell($lastColumn.'2')->getValue());
            $book->disconnectWorksheets();
        } finally {
            unlink($path);
        }
    }

    public function test_gross_keeps_basic_salary_when_no_eligible_allowance_has_a_positive_rate(): void
    {
        $staff = $this->staff();
        $missing = AllowanceType::query()->create(['code' => 'report_missing', 'name' => 'Unpriced call', 'status' => 'active']);
        $zero = AllowanceType::query()->create(['code' => 'report_zero', 'name' => 'Zero rate', 'status' => 'active']);
        $rate = SalaryStructureRate::query()->create(['salary_scale_id' => $this->scale->id, 'level' => 6, 'step' => 2, 'basic_salary' => 63135.30]);
        $rate->rateAllowances()->create(['allowance_type_id' => $zero->id, 'amount' => 0]);
        foreach ([$missing, $zero] as $type) {
            $staff->allowanceAssignments()->create(['allowance_type_id' => $type->id, 'is_eligible' => true, 'source' => 'staff_management']);
        }
        $response = $this->actingAs($this->user)->getJson('/api/reports/staff-list')->assertOk()
            ->assertJsonPath('data.0.unpriced_allowances', ['Unpriced call']);
        $this->assertEquals(0, $response->json('data.0.allowance_total'));
        $this->assertEquals(63135.30, $response->json('data.0.gross_salary'));
        $allowances = collect($response->json('data.0.allowances'))->keyBy('id');
        $this->assertNull($allowances[$missing->id]['amount']);
        $this->assertEquals(0, $allowances[$zero->id]['amount']);
    }

    public function test_report_and_filter_options_respect_mda_tenancy(): void
    {
        $visible = $this->staff();
        $otherMda = Mda::factory()->create();
        $other = $this->staff(['mda_id' => $otherMda->id, 'staff_number' => 'OTHER']);
        $department = Department::query()->create(['mda_id' => $otherMda->id, 'code' => 'OTHER', 'name' => 'Hidden department']);
        $station = Station::query()->create(['mda_id' => $otherMda->id, 'code' => 'OTHER', 'name' => 'Hidden station']);
        $this->actingAs($this->user)->getJson('/api/reports/staff-list')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $visible->id)->assertJsonMissing(['id' => $other->id]);
        $this->getJson('/api/reports/staff-list?mda_id='.$otherMda->id)->assertForbidden();
        $this->getJson('/api/reports/staff-list/export?mda_id='.$otherMda->id)->assertForbidden();
        $this->getJson('/api/reports/staff-list?department_id='.$department->id)->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/reports/staff-list/options')->assertOk()
            ->assertJsonCount(1, 'data.mdas')->assertJsonCount(1, 'data.departments')->assertJsonCount(1, 'data.stations')
            ->assertJsonMissing(['name' => $department->name])->assertJsonMissing(['name' => $station->name]);
    }

    public function test_report_respects_department_and_station_restrictions(): void
    {
        $visible = $this->staff();
        $hidden = $this->staff(['staff_number' => 'HIDDEN']);
        $otherDepartment = Department::query()->create(['mda_id' => $this->mda->id, 'code' => 'OTHER', 'name' => 'Other department']);
        $hidden->currentEmployment()->update(['department_id' => $otherDepartment->id]);
        $this->user->accessScopes()->create(['scope_type' => 'department', 'mda_id' => $this->mda->id, 'department_id' => $this->department->id]);
        $this->actingAs($this->user)->getJson('/api/reports/staff-list')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $visible->id);
        $this->getJson('/api/reports/staff-list/options')->assertOk()->assertJsonCount(1, 'data.departments');
        $this->user->forceFill(['station_id' => $this->station->id])->save();
        $hidden->currentEmployment()->update(['department_id' => $this->department->id, 'station_id' => null]);
        $this->getJson('/api/reports/staff-list')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $visible->id);
        $this->assertWorkbookStaffNumbers(['000123']);
    }

    public function test_report_permissions_are_required_and_export_is_separately_authorized(): void
    {
        $this->staff();
        $this->getJson('/api/reports/staff-list')->assertUnauthorized();
        $this->user->revokePermissionTo(['view-reports', 'export-reports']);
        $this->actingAs($this->user)->getJson('/api/reports/staff-list')->assertForbidden();
        $this->getJson('/api/reports/staff-list/options')->assertForbidden();
        $this->getJson('/api/reports/staff-list/export')->assertForbidden();
        $this->user->givePermissionTo('view-reports');
        $this->getJson('/api/reports/staff-list')->assertOk();
        $this->getJson('/api/reports/staff-list/export')->assertForbidden();
        $this->user->revokePermissionTo('view-reports');
        $this->user->givePermissionTo('export-reports');
        $this->getJson('/api/reports/staff-list')->assertOk();
        $this->assertWorkbookStaffNumbers(['000123']);
    }

    public function test_excel_exports_every_matching_record_preserves_identifiers_and_disables_formulas(): void
    {
        $this->staff(['full_name' => '=HYPERLINK("https://example.invalid")']);
        for ($i = 1; $i <= 12; $i++) {
            $this->staff(['staff_number' => 'STAFF-'.$i, 'full_name' => sprintf('Staff %02d', $i)]);
        }
        $this->staff(['staff_number' => 'RETIRED', 'status' => 'retired']);
        $this->staff(['staff_number' => 'DELETED'])->delete();
        $this->staff(['staff_number' => 'OTHER-MDA', 'mda_id' => Mda::factory()->create()->id]);
        $this->actingAs($this->user)->getJson('/api/reports/staff-list?per_page=10&status=active')->assertOk()->assertJsonCount(10, 'data')->assertJsonPath('meta.total', 13);
        $this->getJson('/api/reports/staff-list?per_page=500&status=active')->assertOk()
            ->assertJsonPath('meta.per_page', 500)->assertJsonCount(13, 'data')->assertJsonPath('meta.total', 13);
        $response = $this->get('/api/reports/staff-list/export?per_page=10&page=2&status=active')->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();
        try {
            Cell::setValueBinder(new DefaultValueBinder);
            $book = IOFactory::load($path);
            $sheet = $book->getActiveSheet();
            $this->assertSame(14, $sheet->getHighestRow());
            $this->assertSame('000123', $sheet->getCell('A2')->getValue());
            $this->assertSame('000456', $sheet->getCell('C2')->getValue());
            $this->assertSame('08012345678', $sheet->getCell('G2')->getValue());
            $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('D2')->getDataType());
            $this->assertSame('E2', $sheet->getFreezePane());
            $this->assertSame('First appointment', $sheet->getCell('S1')->getValue());
            $this->assertSame('01-06-2015', $sheet->getCell('S2')->getFormattedValue());
            $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('S2')->getDataType());
            $book->disconnectWorksheets();
        } finally {
            unlink($path);
        }
        $this->assertWorkbookStaffNumbers(['STAFF-12'], '?search=STAFF-12&department_id='.$this->department->id.'&station_id='.$this->station->id.'&salary_scale_id='.$this->scale->id);
    }

    public function test_report_falls_back_to_staff_number_and_exports_dates_in_day_month_year_order(): void
    {
        foreach ([null, '', '   ', '00098765'] as $index => $cno) {
            $staff = $this->staff(['staff_number' => '0001234'.$index, 'legacy_cno' => $cno]);
            $staff->currentEmployment->update([
                'date_last_promotion' => '2023-01-02', 'next_promotion_date' => '2026-01-02',
                'expected_retirement_date' => '2050-02-03',
            ]);
        }
        $this->staff(['staff_number' => 'NO-DATE', 'date_of_birth' => null])->currentEmployment()->delete();
        $expected = ['00012340', '00012341', '00012342', '00098765', '000123'];
        $preview = $this->actingAs($this->user)->getJson('/api/reports/staff-list')->assertOk();
        $this->assertSame($expected, array_column($preview->json('data'), 'cno'));
        // API date values stay usable by date inputs and sorting.
        $preview->assertJsonPath('data.0.date_of_birth', '1990-02-03');
        $response = $this->get('/api/reports/staff-list/export')->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();
        try {
            Cell::setValueBinder(new DefaultValueBinder);
            $book = IOFactory::load($path);
            $sheet = $book->getActiveSheet();
            $this->assertSame($expected, array_column($sheet->rangeToArray('B2:B6'), 0));
            $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('B2')->getDataType());
            foreach (['F' => '03-02-1990', 'S' => '01-06-2015', 'T' => '02-01-2023', 'U' => '02-01-2026', 'V' => '03-02-2050'] as $column => $date) {
                $this->assertSame($date, $sheet->getCell($column.'2')->getFormattedValue());
                $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell($column.'2')->getDataType());
                $this->assertNull($sheet->getCell($column.'6')->getValue());
            }
            $book->disconnectWorksheets();
        } finally {
            unlink($path);
        }
    }

    public function test_staff_record_slip_prints_fallback_cno_and_formatted_dates(): void
    {
        $this->view('pdf.staff-record-slip', [
            'staff' => [
                'full_name' => 'Test Officer', 'staff_number' => '00012340', 'legacy_cno' => ' ',
                'date_of_birth' => '1990-02-03',
                'current_employment' => [
                    'date_first_appointment' => '2015-06-01', 'date_last_promotion' => '2023-01-02',
                    'expected_retirement_date' => '2050-02-03',
                ],
            ],
            'generatedAt' => now(), 'stateName' => 'Niger State', 'logoData' => null, 'signatureData' => null,
        ])->assertSee('CNO / Staff number</td><td class="fact-value">00012340</td>', false)
            ->assertSee('03-02-1990')->assertSee('01-06-2015')->assertSee('02-01-2023')
            ->assertSee('03-02-2050')->assertSee('10-09-2026')
            ->assertDontSee('1990-02-03')->assertDontSee('2015-06-01');
    }

    public function test_invalid_filters_and_unbounded_page_sizes_are_rejected(): void
    {
        $this->actingAs($this->user)->getJson('/api/reports/staff-list?per_page=10000&status=invalid&page=0')->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page', 'status', 'page']);
        $this->getJson('/api/reports/staff-list?mda_id=-1')->assertForbidden();
    }

    public function test_selected_filters_determine_the_rows_in_the_generated_table(): void
    {
        $selected = $this->staff();
        $other = $this->staff(['staff_number' => 'OTHER', 'legacy_cno' => 'OTHER', 'legacy_psn' => 'OTHER', 'status' => 'inactive']);
        $department = Department::query()->create(['mda_id' => $this->mda->id, 'code' => 'SECOND', 'name' => 'Second department']);
        $station = Station::query()->create(['mda_id' => $this->mda->id, 'code' => 'SECOND', 'name' => 'Second station']);
        $cadre = Cadre::query()->create(['department_id' => $this->department->id, 'salary_scale_id' => $this->scale->id, 'name' => 'Selected cadre']);
        $selected->currentEmployment()->update(['cadre_id' => $cadre->id]);
        $other->currentEmployment()->update(['department_id' => $department->id, 'station_id' => $station->id]);
        $other->currentSalaryPlacement()->delete();
        $this->actingAs($this->user);
        foreach ([
            'search' => '000456', 'department_id' => $this->department->id,
            'station_id' => $this->station->id, 'cadre_id' => $cadre->id,
            'salary_scale_id' => $this->scale->id, 'status' => 'active',
        ] as $key => $value) {
            $this->getJson('/api/reports/staff-list?'.http_build_query([$key => $value]))
                ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $selected->id);
        }
        $this->getJson('/api/reports/staff-list?department_id='.$this->department->id.'&status=inactive')
            ->assertOk()->assertJsonPath('meta.total', 0);
    }

    protected function staff(array $attributes = []): Staff
    {
        $staff = Staff::query()->create(array_merge([
            'mda_id' => $this->mda->id, 'staff_number' => '000123', 'legacy_cno' => '000123', 'legacy_psn' => '000456',
            'surname' => 'Officer', 'first_name' => 'Test', 'full_name' => 'Test Officer', 'status' => 'active', 'date_of_birth' => '1990-02-03',
        ], $attributes));
        $staff->personalDetail()->create(['phone' => '08012345678', 'state_of_origin' => 'Niger', 'lga' => 'Chanchaga']);
        $staff->employments()->create([
            'mda_id' => $staff->mda_id, 'department_id' => $this->department->id, 'station_id' => $this->station->id,
            'initial_rank' => 'Entry rank', 'date_first_appointment' => '2015-06-01', 'employment_status' => 'active', 'is_current' => true,
        ]);
        $staff->salaryPlacements()->create(['salary_scale_id' => $this->scale->id, 'level' => 6, 'step' => 2, 'is_current' => true]);

        return $staff;
    }

    protected function assertWorkbookStaffNumbers(array $expected, string $query = ''): void
    {
        $response = $this->get('/api/reports/staff-list/export'.$query)->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();
        try {
            $book = IOFactory::load($path);
            $rows = $book->getActiveSheet()->toArray();
            $this->assertSame($expected, array_column(array_slice($rows, 1), 0));
            $book->disconnectWorksheets();
        } finally {
            unlink($path);
        }
    }
}
