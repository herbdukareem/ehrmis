<?php

namespace App\Domain\Procurement\Services;

use App\Domain\Procurement\Models\ProcurementBid;
use App\Domain\Procurement\Models\ProcurementDocument;
use App\Domain\Procurement\Models\ProcurementRequisition;
use App\Domain\Procurement\Models\ProcurementSolicitation;
use App\Domain\Procurement\Models\Vendor;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ProcurementSourcingService
{
    public const METHOD_DIRECT_PURCHASE = 'direct_purchase';
    public const METHOD_RFQ = 'rfq';
    public const METHOD_SELECTIVE_TENDER = 'selective_tender';
    public const METHOD_OPEN_TENDER = 'open_tender';
    public const METHOD_EMERGENCY = 'emergency_procurement';

    public const SUPPORTED_METHODS = [
        self::METHOD_DIRECT_PURCHASE,
        self::METHOD_RFQ,
        self::METHOD_SELECTIVE_TENDER,
        self::METHOD_OPEN_TENDER,
        self::METHOD_EMERGENCY,
    ];

    public function __construct(private AuditLogService $auditLogService) {}

    public function createVendor(array $data, ?User $actor = null): Vendor
    {
        return DB::transaction(function () use ($data, $actor): Vendor {
            $vendor = Vendor::query()->create([
                'mda_id' => $data['mda_id'],
                'vendor_no' => $data['vendor_no'] ?? $this->nextVendorNumber((int) $data['mda_id']),
                'name' => $data['name'],
                'vendor_type' => $data['vendor_type'] ?? 'supplier',
                'registration_number' => $data['registration_number'] ?? null,
                'tax_identification_number' => $data['tax_identification_number'] ?? null,
                'contact_person' => $data['contact_person'] ?? null,
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'address' => $data['address'] ?? null,
                'status' => $data['status'] ?? 'active',
                'metadata' => [
                    ...($data['metadata'] ?? []),
                    'created_by' => $actor?->id,
                ],
            ]);

            $this->auditLogService->logCreated($vendor, ['source' => 'procurement.vendor_created']);

            return $vendor->fresh();
        });
    }

    public function createSolicitation(ProcurementRequisition $requisition, array $data, ?User $actor = null): ProcurementSolicitation
    {
        return DB::transaction(function () use ($requisition, $data, $actor): ProcurementSolicitation {
            $requisition = ProcurementRequisition::query()->whereKey($requisition->id)->lockForUpdate()->firstOrFail();

            if ($requisition->status !== 'approved') {
                throw ValidationException::withMessages(['requisition' => 'Only approved requisitions can enter sourcing.']);
            }

            $method = $data['procurement_method'] ?? self::METHOD_RFQ;
            $this->assertSupportedMethod($method);

            $solicitation = ProcurementSolicitation::query()->create([
                'mda_id' => $requisition->mda_id,
                'procurement_requisition_id' => $requisition->id,
                'solicitation_no' => $data['solicitation_no'] ?? $this->nextSolicitationNumber($requisition->mda_id),
                'title' => $data['title'] ?? $requisition->title,
                'description' => $data['description'] ?? $requisition->description,
                'procurement_method' => $method,
                'estimated_amount' => $data['estimated_amount'] ?? $requisition->total_amount,
                'currency' => $data['currency'] ?? 'NGN',
                'issue_date' => $data['issue_date'] ?? now()->toDateString(),
                'closing_at' => $data['closing_at'] ?? null,
                'status' => $data['status'] ?? 'draft',
                'prepared_by' => $actor?->id ?? $data['prepared_by'] ?? null,
                'metadata' => [
                    ...($data['metadata'] ?? []),
                    'requisition_no' => $requisition->requisition_no,
                ],
            ]);

            $this->auditLogService->logCreated($solicitation, ['source' => 'procurement.solicitation_created']);

            return $solicitation->fresh(['requisition']);
        });
    }

    public function publishSolicitation(ProcurementSolicitation $solicitation, ?User $actor = null): ProcurementSolicitation
    {
        return DB::transaction(function () use ($solicitation, $actor): ProcurementSolicitation {
            $solicitation = ProcurementSolicitation::query()->whereKey($solicitation->id)->lockForUpdate()->firstOrFail();

            if (! in_array($solicitation->status, ['draft', 'published'], true)) {
                throw ValidationException::withMessages(['solicitation' => 'Only draft solicitations can be published.']);
            }

            $before = $solicitation->toArray();
            $solicitation->forceFill([
                'status' => 'published',
                'published_by' => $actor?->id ?? $solicitation->published_by,
                'published_at' => $solicitation->published_at ?? now(),
            ])->save();

            $this->auditLogService->logUpdated($solicitation, $before, ['source' => 'procurement.solicitation_published']);

            return $solicitation->fresh();
        });
    }

    public function closeSolicitation(ProcurementSolicitation $solicitation, ?User $actor = null): ProcurementSolicitation
    {
        return DB::transaction(function () use ($solicitation, $actor): ProcurementSolicitation {
            $solicitation = ProcurementSolicitation::query()->whereKey($solicitation->id)->lockForUpdate()->firstOrFail();

            if (! in_array($solicitation->status, ['published', 'closed'], true)) {
                throw ValidationException::withMessages(['solicitation' => 'Only published solicitations can be closed.']);
            }

            $before = $solicitation->toArray();
            $solicitation->forceFill([
                'status' => 'closed',
                'closed_by' => $actor?->id ?? $solicitation->closed_by,
                'closed_at' => $solicitation->closed_at ?? now(),
            ])->save();

            $this->auditLogService->logUpdated($solicitation, $before, ['source' => 'procurement.solicitation_closed']);

            return $solicitation->fresh();
        });
    }

    public function receiveBid(ProcurementSolicitation $solicitation, Vendor $vendor, array $data, ?User $actor = null): ProcurementBid
    {
        return DB::transaction(function () use ($solicitation, $vendor, $data, $actor): ProcurementBid {
            $solicitation = ProcurementSolicitation::query()->whereKey($solicitation->id)->lockForUpdate()->firstOrFail();
            $vendor = Vendor::query()->whereKey($vendor->id)->firstOrFail();

            if ((int) $vendor->mda_id !== (int) $solicitation->mda_id) {
                throw ValidationException::withMessages(['vendor_id' => 'This vendor belongs to another MDA.']);
            }

            if ($vendor->status !== 'active') {
                throw ValidationException::withMessages(['vendor_id' => 'Only active vendors can submit bids or quotations.']);
            }

            if (! in_array($solicitation->status, ['published', 'closed'], true)) {
                throw ValidationException::withMessages(['solicitation' => 'Bids can only be received for published or closed solicitations.']);
            }

            $amount = round((float) ($data['quoted_amount'] ?? 0), 2);
            if ($amount <= 0) {
                throw ValidationException::withMessages(['quoted_amount' => 'Quoted amount must be greater than zero.']);
            }

            $bid = ProcurementBid::query()->firstOrNew([
                'procurement_solicitation_id' => $solicitation->id,
                'vendor_id' => $vendor->id,
            ]);
            $before = $bid->exists ? $bid->toArray() : [];
            $bid->fill([
                'mda_id' => $solicitation->mda_id,
                'bid_no' => $bid->bid_no ?? $data['bid_no'] ?? $this->nextBidNumber($solicitation->mda_id),
                'quoted_amount' => $amount,
                'currency' => $data['currency'] ?? $solicitation->currency,
                'submitted_at' => $data['submitted_at'] ?? now(),
                'valid_until' => $data['valid_until'] ?? null,
                'status' => $data['status'] ?? 'submitted',
                'technical_score' => $data['technical_score'] ?? $bid->technical_score,
                'financial_score' => $data['financial_score'] ?? $bid->financial_score,
                'total_score' => $data['total_score'] ?? $bid->total_score,
                'notes' => $data['notes'] ?? $bid->notes,
                'metadata' => [
                    ...($bid->metadata ?? []),
                    ...($data['metadata'] ?? []),
                    'received_by' => $actor?->id,
                ],
            ]);
            $bid->save();

            if ($before === []) {
                $this->auditLogService->log('procurement.bid_received', $bid, [], $bid->toArray(), [
                    'mda_id' => $bid->mda_id,
                    'procurement_solicitation_id' => $solicitation->id,
                    'vendor_id' => $vendor->id,
                ]);
            } else {
                $this->auditLogService->log('procurement.bid_updated', $bid, $before, $bid->toArray(), [
                    'mda_id' => $bid->mda_id,
                    'procurement_solicitation_id' => $solicitation->id,
                    'vendor_id' => $vendor->id,
                ]);
            }

            return $bid->fresh(['vendor', 'solicitation']);
        });
    }

    public function attachDocument(Model $documentable, array $data, ?User $actor = null): ProcurementDocument
    {
        return DB::transaction(function () use ($documentable, $data, $actor): ProcurementDocument {
            $mdaId = $this->documentableMdaId($documentable);
            $file = $data['file'] ?? null;
            $path = null;

            if ($file instanceof UploadedFile) {
                $path = $file->store($this->documentPath($mdaId, $documentable), 'local');
            }

            if (! $path && empty($data['external_url'])) {
                throw ValidationException::withMessages(['file' => 'Attach a file or provide an external document URL.']);
            }

            $document = ProcurementDocument::query()->create([
                'mda_id' => $mdaId,
                'documentable_type' => $documentable::class,
                'documentable_id' => $documentable->getKey(),
                'title' => $data['title'],
                'document_type' => $data['document_type'] ?? 'supporting_document',
                'file_path' => $path,
                'file_mime_type' => $file instanceof UploadedFile ? $file->getMimeType() : null,
                'file_size' => $file instanceof UploadedFile ? $file->getSize() : null,
                'external_url' => $data['external_url'] ?? null,
                'notes' => $data['notes'] ?? null,
                'uploaded_by' => $actor?->id,
            ]);

            $this->auditLogService->logCreated($document, [
                'source' => 'procurement.document_attached',
                'documentable_type' => $documentable::class,
                'documentable_id' => $documentable->getKey(),
            ]);

            return $document->fresh(['uploadedBy']);
        });
    }

    public function deleteDocument(ProcurementDocument $document, ?User $actor = null): void
    {
        DB::transaction(function () use ($document, $actor): void {
            $document = ProcurementDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            $before = $document->toArray();

            if ($document->file_path) {
                Storage::disk('local')->delete($document->file_path);
            }

            $document->delete();

            $this->auditLogService->log('procurement.document_deleted', ProcurementDocument::class, $before, [], [
                'mda_id' => $before['mda_id'] ?? null,
                'deleted_by' => $actor?->id,
            ]);
        });
    }

    protected function assertSupportedMethod(string $method): void
    {
        if (! in_array($method, self::SUPPORTED_METHODS, true)) {
            throw ValidationException::withMessages(['procurement_method' => 'Unsupported procurement method.']);
        }
    }

    protected function documentableMdaId(Model $documentable): int
    {
        $mdaId = $documentable->getAttribute('mda_id');

        if (! $mdaId) {
            throw ValidationException::withMessages(['mda_id' => 'Procurement document target must be MDA scoped.']);
        }

        return (int) $mdaId;
    }

    protected function documentPath(int $mdaId, Model $documentable): string
    {
        return 'procurement/'.$mdaId.'/'.class_basename($documentable).'/'.$documentable->getKey().'/documents';
    }

    protected function nextVendorNumber(int $mdaId): string
    {
        $next = Vendor::query()->where('mda_id', $mdaId)->count() + 1;

        return 'VEN-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }

    protected function nextSolicitationNumber(int $mdaId): string
    {
        $next = ProcurementSolicitation::query()->where('mda_id', $mdaId)->count() + 1;

        return 'SRC-'.now()->format('Y').'-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }

    protected function nextBidNumber(int $mdaId): string
    {
        $next = ProcurementBid::query()->where('mda_id', $mdaId)->count() + 1;

        return 'BID-'.now()->format('Y').'-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }
}
