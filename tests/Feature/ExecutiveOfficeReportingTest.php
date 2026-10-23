<?php

namespace Tests\Feature;

use App\Domain\ExecutiveOffice\Services\OfficeRecordService;
use App\Domain\ExecutiveOffice\Services\OfficeReportingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Concerns\BuildsExecutiveOffice;
use Tests\TestCase;

class ExecutiveOfficeReportingTest extends TestCase
{
    use BuildsExecutiveOffice, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->officeFixtures();
    }

    protected function letter(string $subject, string $classification = 'standard')
    {
        return app(OfficeRecordService::class)->create($this->executive, $this->office, ['type' => 'correspondence', 'subject' => $subject, 'priority' => 'urgent', 'confidentiality' => $classification, 'details' => ['direction' => 'incoming', 'sender' => 'Office']]);
    }

    public function test_dashboard_and_briefings_preserve_confidentiality_and_role_specific_content(): void
    {
        $this->letter('General matter');
        $this->letter('Secret matter', 'executive_only');
        $this->actingAs($this->pa)->getJson($this->officeUrl('dashboard'))->assertOk()->assertJsonPath('data.role', 'Office coordination')->assertJsonPath('data.counts.urgent', 1);
        $this->actingAs($this->executive)->getJson($this->officeUrl('dashboard'))->assertOk()->assertJsonPath('data.role', 'Executive')->assertJsonPath('data.counts.urgent', 2);
        $brief = app(OfficeReportingService::class)->prepareBriefing($this->executive, $this->office, now()->toDateString());
        $this->assertSame('executive_only', $brief->confidentiality);
        $this->actingAs($this->pa)->getJson($this->officeUrl('records/'.$brief->id))->assertNotFound();
        $this->actingAs($this->admin)->getJson($this->officeUrl('dashboard'))->assertForbidden();
    }

    public function test_reports_and_excel_use_identical_scopes_and_keep_imported_subjects_out_of_formulas(): void
    {
        $this->letter('=HYPERLINK("https://example.invalid")');
        $this->letter('Hidden secret', 'executive_only');
        $this->actingAs($this->pa)->getJson($this->officeUrl('reports'))->assertOk()->assertJsonPath('summary.total', 1);
        $response = $this->get($this->officeUrl('reports/export'))->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();
        try {
            Cell::setValueBinder(new DefaultValueBinder);
            $book = IOFactory::load($path);
            $sheet = $book->getActiveSheet();
            $this->assertSame(2, $sheet->getHighestRow());
            $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('C2')->getDataType());
            $book->disconnectWorksheets();
        } finally {
            unlink($path);
        }
        $this->actingAs($this->officer)->getJson($this->officeUrl('reports'))->assertForbidden();
    }

    public function test_printable_pack_is_a_pdf_and_requires_office_team_access(): void
    {
        $record = $this->letter('Printable matter');
        $this->actingAs($this->pa)->get($this->officeUrl('records/'.$record->id.'/print'))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->actingAs($this->admin)->get($this->officeUrl('records/'.$record->id.'/print'))->assertForbidden();
    }
}
