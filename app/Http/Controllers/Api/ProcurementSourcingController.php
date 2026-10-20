<?php

namespace App\Http\Controllers\Api;

use App\Domain\Procurement\Models\ProcurementBid;
use App\Domain\Procurement\Models\ProcurementDocument;
use App\Domain\Procurement\Models\ProcurementRequisition;
use App\Domain\Procurement\Models\ProcurementSolicitation;
use App\Domain\Procurement\Models\Vendor;
use App\Domain\Procurement\Services\ProcurementSourcingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProcurementSourcingController extends BudgetProcurementController
{
    private const DOCUMENT_TARGETS = [
        'vendors' => [Vendor::class, 'manage-vendors'],
        'solicitations' => [ProcurementSolicitation::class, 'manage-sourcing'],
        'bids' => [ProcurementBid::class, 'manage-sourcing'],
    ];

    public function vendors(Request $request): JsonResponse
    {
        return $this->listing($request, Vendor::query());
    }

    public function storeVendor(Request $request, ProcurementSourcingService $service): JsonResponse
    {
        $mdaId = $this->selectedMda($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'vendor_type' => ['sometimes', Rule::in(['supplier', 'contractor', 'consultant', 'service_provider'])],
            'registration_number' => ['nullable', 'string', 'max:255'],
            'tax_identification_number' => ['nullable', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:10000'],
        ]);

        return $this->result($service->createVendor(['mda_id' => $mdaId, ...$data], $request->user()), 201);
    }

    public function showVendor(Request $request, Vendor $vendor): JsonResponse
    {
        $this->assertMda($request, $vendor->mda_id);

        return $this->result($vendor->load('documents')->loadCount(['bids', 'awards', 'purchaseOrders']));
    }

    public function solicitations(Request $request): JsonResponse
    {
        return $this->listing($request, ProcurementSolicitation::query());
    }

    public function storeSolicitation(Request $request, ProcurementRequisition $requisition, ProcurementSourcingService $service): JsonResponse
    {
        $this->assertMda($request, $requisition->mda_id);
        $this->assertCycleWritable($requisition->budgetCycle);
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'procurement_method' => ['required', Rule::in(ProcurementSourcingService::SUPPORTED_METHODS)],
            'issue_date' => ['sometimes', 'date_format:Y-m-d'],
            'closing_at' => ['nullable', 'date', 'after_or_equal:issue_date'],
        ]);

        return $this->result($service->createSolicitation($requisition, $data, $request->user()), 201);
    }

    public function showSolicitation(Request $request, ProcurementSolicitation $solicitation): JsonResponse
    {
        $this->assertMda($request, $solicitation->mda_id);

        return $this->result($solicitation->load(['requisition', 'bids.vendor', 'evaluations', 'award', 'documents']));
    }

    public function publish(Request $request, ProcurementSolicitation $solicitation, ProcurementSourcingService $service): JsonResponse
    {
        $this->assertMda($request, $solicitation->mda_id);
        $this->assertCycleWritable($solicitation->requisition?->budgetCycle);

        return $this->result($service->publishSolicitation($solicitation, $request->user()));
    }

    public function close(Request $request, ProcurementSolicitation $solicitation, ProcurementSourcingService $service): JsonResponse
    {
        $this->assertMda($request, $solicitation->mda_id);
        $this->assertCycleWritable($solicitation->requisition?->budgetCycle);

        return $this->result($service->closeSolicitation($solicitation, $request->user()));
    }

    public function receiveBid(Request $request, ProcurementSolicitation $solicitation, ProcurementSourcingService $service): JsonResponse
    {
        $this->assertMda($request, $solicitation->mda_id);
        $this->assertCycleWritable($solicitation->requisition?->budgetCycle);
        $data = $request->validate([
            'vendor_id' => ['required', 'integer', $this->reference(Vendor::class, $solicitation->mda_id, ['status' => 'active'])],
            'quoted_amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:9999999999999999.99'],
            'valid_until' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:10000'],
        ]);
        $existing = $solicitation->bids()->where('vendor_id', $data['vendor_id'])->first();
        if ($existing) {
            $this->assertEditable($existing, ['submitted']);
        }

        return $this->result($service->receiveBid($solicitation, Vendor::query()->findOrFail($data['vendor_id']), $data, $request->user()), $existing ? 200 : 201);
    }

    public function showBid(Request $request, ProcurementBid $bid): JsonResponse
    {
        $this->assertMda($request, $bid->mda_id);

        return $this->result($bid->load(['vendor', 'solicitation', 'evaluation', 'documents']));
    }

    public function storeDocument(Request $request, string $targetType, int $targetId, ProcurementSourcingService $service): JsonResponse
    {
        abort_unless(isset(self::DOCUMENT_TARGETS[$targetType]), 404);
        [$model, $permission] = self::DOCUMENT_TARGETS[$targetType];
        abort_unless($request->user()->can($permission), 403);
        $target = $model::query()->findOrFail($targetId);
        $this->assertMda($request, $target->mda_id);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'document_type' => ['sometimes', 'string', 'max:100'],
            'file' => ['required_without:external_url', 'nullable', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx,csv,txt', 'max:10240'],
            'external_url' => ['required_without:file', 'nullable', 'url:http,https', 'max:2048'],
            'notes' => ['nullable', 'string', 'max:10000'],
        ]);

        return $this->result($service->attachDocument($target, $data, $request->user()), 201);
    }

    public function downloadDocument(Request $request, ProcurementDocument $document): StreamedResponse|JsonResponse
    {
        $this->assertMda($request, $document->mda_id);
        if (! $document->file_path) {
            return $this->result(['external_url' => $document->external_url]);
        }
        abort_unless(Storage::disk('local')->exists($document->file_path), 404);

        return Storage::disk('local')->download($document->file_path);
    }

    public function deleteDocument(Request $request, ProcurementDocument $document, ProcurementSourcingService $service): JsonResponse
    {
        $this->assertMda($request, $document->mda_id);
        $target = $document->documentable;
        abort_unless($target && (int) $target->mda_id === (int) $document->mda_id, 404);
        $permission = collect(self::DOCUMENT_TARGETS)->first(fn ($config): bool => $target instanceof $config[0]);
        abort_unless($permission && $request->user()->can($permission[1]), 403);
        $service->deleteDocument($document, $request->user());

        return response()->json(['message' => 'Document deleted.']);
    }
}
