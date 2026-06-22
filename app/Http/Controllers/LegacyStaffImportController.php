<?php

namespace App\Http\Controllers;

use App\Domain\Legacy\Models\LegacyStaffImportBatch;
use App\Domain\Legacy\Services\LegacyStaffImportQueryService;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Mda;
use App\Domain\Organization\Models\Station;
use App\Domain\Staff\Models\Cadre;
use App\Domain\Staff\Models\Rank;
use App\Domain\Staff\Models\SalaryScale;
use App\Http\Resources\LegacyStaffImportBatchResource;
use App\Http\Resources\LegacyStaffImportRowResource;
use App\Http\Resources\LegacyStaffImportSummaryResource;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LegacyStaffImportController extends Controller
{
    public function index(Request $request, LegacyStaffImportQueryService $queryService): Response
    {
        $this->authorize('viewAny', LegacyStaffImportBatch::class);

        $filters = $request->only(['status', 'source_table', 'date_from', 'date_to', 'per_page']);
        $batches = $queryService->paginateBatches($filters, $request->user(), (int) ($filters['per_page'] ?? 15));

        return Inertia::render('LegacyStaffImports/Index', [
            'batches' => LegacyStaffImportBatchResource::collection($batches)->response()->getData(true),
            'filters' => $filters,
            'filterOptions' => [
                'statuses' => $queryService->batchStatusOptions(),
                'source_tables' => $queryService->sourceTableOptions()->values()->all(),
            ],
        ]);
    }

    public function show(Request $request, LegacyStaffImportBatch $batch, LegacyStaffImportQueryService $queryService): Response
    {
        $this->authorize('view', $batch);

        $filters = $request->only([
            'search',
            'status',
            'warning_code',
            'error_code',
            'severity',
            'mda_id',
            'department_id',
            'station_id',
            'salary_scale_id',
            'cadre_id',
            'rank_id',
            'level',
            'missing_mda',
            'missing_department',
            'missing_station',
            'missing_cadre',
            'missing_rank',
            'unresolved_call_allowance',
            'published',
            'per_page',
        ]);

        $summary = $queryService->summarizeBatch($batch, $request->user());
        $rows = $queryService->paginateRows($batch, $filters, $request->user(), (int) ($filters['per_page'] ?? 20));
        $issueOptions = $queryService->issueOptionsForBatch($batch, $request->user());

        return Inertia::render('LegacyStaffImports/Show', [
            'batch' => LegacyStaffImportBatchResource::make($batch)->resolve(),
            'summary' => LegacyStaffImportSummaryResource::make($summary)->resolve(),
            'rows' => LegacyStaffImportRowResource::collection($rows)->response()->getData(true),
            'filters' => $filters,
            'filterOptions' => array_merge($issueOptions, $this->buildFilterOptions($request->user())),
            'can' => [
                'publish' => $request->user()->can('publish', $batch),
                'submitApproval' => $request->user()->can('submitApproval', $batch),
                'approveApproval' => $request->user()->can('approveApproval', $batch),
                'rejectApproval' => $request->user()->can('rejectApproval', $batch),
            ],
            'latestPublication' => $batch->publications()->latest('published_at')->first()?->summary,
        ]);
    }

    protected function buildFilterOptions($user): array
    {
        return [
            'mdas' => Mda::query()->visibleToUser($user)->orderBy('name')->get(['id', 'code', 'name'])->toArray(),
            'departments' => Department::query()->orderBy('name')->get(['id', 'mda_id', 'name'])->toArray(),
            'stations' => Station::query()->orderBy('name')->get(['id', 'mda_id', 'name'])->toArray(),
            'salary_scales' => SalaryScale::query()->orderBy('code')->get(['id', 'mda_id', 'code', 'name'])->toArray(),
            'cadres' => Cadre::query()->orderBy('name')->get(['id', 'department_id', 'salary_scale_id', 'name'])->toArray(),
            'ranks' => Rank::query()->orderBy('name')->get(['id', 'cadre_id', 'salary_scale_id', 'name', 'level'])->toArray(),
            'row_statuses' => ['staged', 'invalid', 'ready_to_publish', 'published'],
            'severity_options' => ['warning', 'error'],
            'published_options' => [
                ['value' => '1', 'label' => 'Published'],
                ['value' => '0', 'label' => 'Unpublished'],
            ],
        ];
    }
}
