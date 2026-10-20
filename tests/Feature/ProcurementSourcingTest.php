<?php

namespace Tests\Feature;

use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Mda;
use App\Domain\Procurement\Models\ProcurementBid;
use App\Domain\Procurement\Models\ProcurementDocument;
use App\Domain\Procurement\Models\ProcurementRequisition;
use App\Domain\Procurement\Models\ProcurementSolicitation;
use App\Domain\Procurement\Models\Vendor;
use App\Domain\Procurement\Services\ProcurementSourcingService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProcurementSourcingTest extends TestCase
{
    use RefreshDatabase;

    public function test_vendor_creation_and_sourcing_lifecycle_receive_bids_and_documents(): void
    {
        Storage::fake('local');
        [$mda, $department, $actor] = $this->organization();
        $requisition = $this->approvedRequisition($mda, $department, 1200000);
        $service = app(ProcurementSourcingService::class);

        $vendor = $service->createVendor([
            'mda_id' => $mda->id,
            'name' => 'Alpha Supplies Ltd',
            'vendor_type' => 'supplier',
            'email' => 'alpha@example.test',
        ], $actor);
        $solicitation = $service->createSolicitation($requisition, [
            'procurement_method' => ProcurementSourcingService::METHOD_RFQ,
            'title' => 'RFQ for office equipment',
            'closing_at' => '2026-10-10 12:00:00',
        ], $actor);
        $published = $service->publishSolicitation($solicitation, $actor);
        $solicitationDocument = $service->attachDocument($published, [
            'title' => 'RFQ document',
            'document_type' => 'rfq_document',
            'file' => UploadedFile::fake()->create('rfq.pdf', 32, 'application/pdf'),
        ], $actor);
        $bid = $service->receiveBid($published, $vendor, [
            'quoted_amount' => 1100000,
            'valid_until' => '2026-12-31',
            'notes' => 'Delivery within 14 days',
        ], $actor);
        $bidDocument = $service->attachDocument($bid, [
            'title' => 'Vendor quotation',
            'document_type' => 'quotation',
            'file' => UploadedFile::fake()->create('quotation.pdf', 24, 'application/pdf'),
        ], $actor);

        $this->assertSame($mda->id, $vendor->mda_id);
        $this->assertStringStartsWith('VEN-', $vendor->vendor_no);
        $this->assertSame('published', $published->status);
        $this->assertSame(ProcurementSourcingService::METHOD_RFQ, $published->procurement_method);
        $this->assertSame($vendor->id, $bid->vendor_id);
        $this->assertEquals(1100000.0, (float) $bid->quoted_amount);
        $this->assertStringStartsWith('BID-', $bid->bid_no);
        Storage::disk('local')->assertExists($solicitationDocument->file_path);
        Storage::disk('local')->assertExists($bidDocument->file_path);
        $this->assertDatabaseHas('audit_logs', ['event_code' => 'procurement.bid_received']);

        $closed = $service->closeSolicitation($published, $actor);
        $this->assertSame('closed', $closed->status);

        $service->deleteDocument($bidDocument, $actor);
        Storage::disk('local')->assertMissing($bidDocument->file_path);
        $this->assertSame(1, ProcurementDocument::query()->count());
    }

    public function test_receiving_another_bid_from_same_vendor_updates_existing_bid(): void
    {
        [$mda, $department, $actor] = $this->organization();
        $service = app(ProcurementSourcingService::class);
        $vendor = $service->createVendor(['mda_id' => $mda->id, 'name' => 'Alpha Supplies Ltd'], $actor);
        $solicitation = $service->publishSolicitation(
            $service->createSolicitation($this->approvedRequisition($mda, $department, 1200000), [
                'procurement_method' => ProcurementSourcingService::METHOD_OPEN_TENDER,
            ], $actor),
            $actor
        );

        $first = $service->receiveBid($solicitation, $vendor, ['quoted_amount' => 1100000], $actor);
        $second = $service->receiveBid($solicitation, $vendor, ['quoted_amount' => 1050000, 'notes' => 'Revised quotation'], $actor);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, ProcurementBid::query()->count());
        $this->assertEquals(1050000.0, (float) $second->quoted_amount);
        $this->assertSame('Revised quotation', $second->notes);
    }

    public function test_unsupported_method_unapproved_requisition_and_foreign_vendor_are_rejected(): void
    {
        [$mda, $department, $actor] = $this->organization();
        [$otherMda] = $this->organization('HMB');
        $service = app(ProcurementSourcingService::class);
        $draftRequisition = $this->approvedRequisition($mda, $department, 1200000, status: 'submitted');

        try {
            $service->createSolicitation($draftRequisition, ['procurement_method' => ProcurementSourcingService::METHOD_RFQ], $actor);
            $this->fail('Expected sourcing to reject an unapproved requisition.');
        } catch (ValidationException $exception) {
            $this->assertSame('Only approved requisitions can enter sourcing.', $exception->errors()['requisition'][0]);
        }

        $approvedRequisition = $this->approvedRequisition($mda, $department, 1200000);

        try {
            $service->createSolicitation($approvedRequisition, ['procurement_method' => 'auction'], $actor);
            $this->fail('Expected sourcing to reject an unsupported method.');
        } catch (ValidationException $exception) {
            $this->assertSame('Unsupported procurement method.', $exception->errors()['procurement_method'][0]);
        }

        $solicitation = $service->publishSolicitation(
            $service->createSolicitation($approvedRequisition, [
                'procurement_method' => ProcurementSourcingService::METHOD_SELECTIVE_TENDER,
            ], $actor),
            $actor
        );
        $foreignVendor = $service->createVendor(['mda_id' => $otherMda->id, 'name' => 'Foreign Vendor'], $actor);

        $this->expectException(ValidationException::class);
        $service->receiveBid($solicitation, $foreignVendor, ['quoted_amount' => 1000000], $actor);
    }

    public function test_vendors_and_solicitations_are_mda_scoped(): void
    {
        [$mda, $department, $actor] = $this->organization();
        [$otherMda, $otherDepartment] = $this->organization('HMB');
        $service = app(ProcurementSourcingService::class);
        $ownVendor = $service->createVendor(['mda_id' => $mda->id, 'name' => 'Own Vendor'], $actor);
        $service->createVendor(['mda_id' => $otherMda->id, 'name' => 'Other Vendor']);
        $ownSolicitation = $service->createSolicitation($this->approvedRequisition($mda, $department, 100000), [
            'procurement_method' => ProcurementSourcingService::METHOD_DIRECT_PURCHASE,
        ], $actor);
        $service->createSolicitation($this->approvedRequisition($otherMda, $otherDepartment, 100000), [
            'procurement_method' => ProcurementSourcingService::METHOD_EMERGENCY,
        ]);

        $this->actingAs($actor);

        $this->assertSame([$ownVendor->id], Vendor::query()->pluck('id')->all());
        $this->assertSame([$ownSolicitation->id], ProcurementSolicitation::query()->pluck('id')->all());
    }

    /**
     * @return array{0:Mda,1:Department,2:User}
     */
    protected function organization(string $code = 'MOH'): array
    {
        $mda = Mda::query()->create([
            'code' => $code,
            'name' => $code === 'MOH' ? 'Ministry of Health' : 'Hospital Management Board',
            'status' => 'active',
        ]);
        $department = Department::query()->create([
            'mda_id' => $mda->id,
            'code' => 'ADMIN',
            'name' => 'Administration',
            'status' => 'active',
        ]);
        $actor = User::factory()->mdaUser($mda)->create();

        return [$mda, $department, $actor];
    }

    protected function approvedRequisition(Mda $mda, Department $department, float $amount, string $status = 'approved'): ProcurementRequisition
    {
        return ProcurementRequisition::query()->create([
            'mda_id' => $mda->id,
            'department_id' => $department->id,
            'requisition_no' => 'REQ-'.$mda->code.'-'.str_pad((string) (ProcurementRequisition::withoutGlobalScopes()->count() + 1), 4, '0', STR_PAD_LEFT),
            'title' => 'Procurement requisition',
            'total_amount' => $amount,
            'status' => $status,
            'budget_review_status' => 'available',
            'approved_at' => $status === 'approved' ? now() : null,
        ]);
    }
}
