<?php

namespace App\Http\Controllers\Api;

use App\Domain\Legacy\Models\LegacyStaffImportBatch;
use App\Domain\Legacy\Models\LegacyStaffImportError;
use App\Domain\Legacy\Models\LegacyStaffImportRow;
use App\Domain\Legacy\Services\LegacyStaffImportIssueResolutionService;
use App\Domain\Legacy\Services\LegacyStaffImportPublicationService;
use App\Domain\Legacy\Services\LegacyStaffImportQueryService;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Mda;
use App\Domain\Organization\Models\Station;
use App\Domain\Staff\Models\Cadre;
use App\Domain\Staff\Models\QualificationType;
use App\Domain\Staff\Models\Rank;
use App\Http\Controllers\Controller;
use App\Http\Resources\LegacyStaffImportBatchResource;
use App\Http\Resources\LegacyStaffImportRowResource;
use App\Http\Resources\LegacyStaffImportSummaryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LegacyStaffImportController extends Controller
{
    public function index(Request $request, LegacyStaffImportQueryService $queryService): JsonResponse
    {
        $this->authorize('viewAny', LegacyStaffImportBatch::class);

        $filters = $request->only(['status', 'source_table', 'date_from', 'date_to', 'per_page']);
        $batches = $queryService->paginateBatches($filters, $request->user(), (int) ($filters['per_page'] ?? 15));

        return response()->json([
            'data' => LegacyStaffImportBatchResource::collection($batches->items())->resolve(),
            'meta' => [
                'current_page' => $batches->currentPage(),
                'last_page' => $batches->lastPage(),
                'per_page' => $batches->perPage(),
                'total' => $batches->total(),
            ],
            'options' => [
                'statuses' => $queryService->batchStatusOptions(),
                'source_tables' => $queryService->sourceTableOptions()->values(),
            ],
        ]);
    }

    public function show(Request $request, LegacyStaffImportBatch $batch, LegacyStaffImportQueryService $queryService): JsonResponse
    {
        $this->authorize('view', $batch);
        $batch->load('approvalWorkflow.steps');

        $filters = $request->only([
            'search', 'status', 'severity', 'published', 'publishable', 'per_page', 'warning_code', 'error_code',
            'missing_mda', 'missing_department', 'missing_station', 'missing_cadre', 'missing_rank',
            'unresolved_call_allowance',
        ]);
        $rows = $queryService->paginateRows($batch, $filters, $request->user(), (int) ($filters['per_page'] ?? 20));

        return response()->json([
            'data' => [
                'batch' => LegacyStaffImportBatchResource::make($batch)->resolve(),
                'summary' => LegacyStaffImportSummaryResource::make($queryService->summarizeBatch($batch, $request->user()))->resolve(),
                'rows' => LegacyStaffImportRowResource::collection($rows->items())->resolve(),
                'can' => [
                    'publish' => $request->user()->can('publish', $batch),
                    'submit_approval' => $request->user()->can('submitApproval', $batch),
                    'approve' => $request->user()->can('approveApproval', $batch),
                    'reject' => $request->user()->can('rejectApproval', $batch),
                ],
                'options' => $queryService->issueOptionsForBatch($batch, $request->user()),
            ],
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
            ],
        ]);
    }

    public function showRow(Request $request, LegacyStaffImportBatch $batch, LegacyStaffImportRow $row, LegacyStaffImportQueryService $queryService): JsonResponse
    {
        $this->ensureRowBelongsToBatch($batch, $row);
        $this->authorize('view', $row);
        $row->load(['errors', 'mda', 'department', 'station', 'cadre', 'rank', 'salaryScale', 'matchedStaff', 'publishedStaff']);

        return response()->json([
            'data' => [
                'batch' => ['id' => $batch->id, 'status' => $batch->status, 'source_table' => $batch->source_table],
                'row' => LegacyStaffImportRowResource::make($row)->resolve(),
                'summary' => LegacyStaffImportSummaryResource::make($queryService->summarizeBatch($batch, $request->user()))->resolve(),
                'mapping_options' => [
                    'mdas' => Mda::query()->visibleToUser($request->user())->orderBy('name')->get(['id', 'code', 'name']),
                    'departments' => Department::query()->when(! $request->user()->hasGlobalMdaAccess(), fn ($query) => $query->where('mda_id', $request->user()->mda_id))->orderBy('name')->get(['id', 'mda_id', 'name']),
                    'stations' => Station::query()->when(! $request->user()->hasGlobalMdaAccess(), fn ($query) => $query->where('mda_id', $request->user()->mda_id))->orderBy('name')->get(['id', 'mda_id', 'name']),
                    'cadres' => Cadre::query()->orderBy('name')->get(['id', 'department_id', 'salary_scale_id', 'name']),
                    'ranks' => Rank::query()->orderBy('name')->get(['id', 'cadre_id', 'salary_scale_id', 'name', 'level']),
                    'qualification_types' => QualificationType::query()->orderBy('name')->get(['id', 'code', 'name']),
                ],
                'can' => [
                    'publish' => $request->user()->can('publish', $row),
                    'resolve' => $request->user()->can('resolveMapping', $row),
                    'ignore_warnings' => $request->user()->can('ignoreWarning', $row),
                ],
            ],
        ]);
    }

    public function ignoreWarning(Request $request, LegacyStaffImportBatch $batch, LegacyStaffImportRow $row, LegacyStaffImportIssueResolutionService $resolutionService): JsonResponse
    {
        $this->ensureRowBelongsToBatch($batch, $row);
        $this->authorize('ignoreWarning', $row);
        $validated = $request->validate(['warning_id' => ['required', 'integer'], 'notes' => ['nullable', 'string', 'max:1000']]);
        $warning = LegacyStaffImportError::query()->where('row_id', $row->id)->where('batch_id', $batch->id)->where('severity', 'warning')->findOrFail($validated['warning_id']);
        $resolutionService->ignoreWarning($warning, $request->user(), $validated['notes'] ?? null);

        return response()->json(['message' => 'Warning marked as reviewed.']);
    }

    public function resolveMapping(Request $request, LegacyStaffImportBatch $batch, LegacyStaffImportRow $row, LegacyStaffImportIssueResolutionService $resolutionService): JsonResponse
    {
        $this->ensureRowBelongsToBatch($batch, $row);
        $this->authorize('resolveMapping', $row);
        $validated = $request->validate([
            'field' => ['required', 'string', 'in:mda,department,station,cadre,rank,qualification_type'],
            'target_id' => ['required', 'integer'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $resolutionService->applyMapping($row, $validated['field'], (int) $validated['target_id'], $request->user(), $validated['notes'] ?? null);

        return response()->json(['message' => 'Mapping resolved successfully.']);
    }

    public function publishRow(Request $request, LegacyStaffImportBatch $batch, LegacyStaffImportRow $row, LegacyStaffImportPublicationService $publicationService): JsonResponse
    {
        $this->ensureRowBelongsToBatch($batch, $row);
        $this->authorize('publish', $row);
        $result = $publicationService->publishRow($row, $request->user());

        return response()->json([
            'message' => match ($result['status']) {
                'published' => 'Import row published successfully.',
                'skipped_already_published' => 'This import row was already published.',
                default => 'Import row could not be published because blocking issues remain.',
            },
            'data' => $result,
        ]);
    }

    protected function ensureRowBelongsToBatch(LegacyStaffImportBatch $batch, LegacyStaffImportRow $row): void
    {
        abort_unless((int) $row->batch_id === (int) $batch->id, 404);
    }
}
