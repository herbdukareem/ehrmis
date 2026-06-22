<?php

namespace App\Http\Controllers;

use App\Domain\Legacy\Models\LegacyStaffImportBatch;
use App\Domain\Legacy\Models\LegacyStaffImportRow;
use App\Domain\Legacy\Services\LegacyStaffImportQueryService;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Mda;
use App\Domain\Organization\Models\Station;
use App\Domain\Staff\Models\Cadre;
use App\Domain\Staff\Models\QualificationType;
use App\Domain\Staff\Models\Rank;
use App\Http\Resources\LegacyStaffImportRowResource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LegacyStaffImportRowController extends Controller
{
    public function index(Request $request, LegacyStaffImportBatch $batch): RedirectResponse
    {
        $this->authorize('view', $batch);

        return redirect()->route('legacy-staff-imports.show', array_merge(
            ['batch' => $batch],
            $request->query()
        ));
    }

    public function show(Request $request, LegacyStaffImportBatch $batch, LegacyStaffImportRow $row, LegacyStaffImportQueryService $queryService): Response
    {
        abort_unless((int) $row->batch_id === (int) $batch->id, 404);
        $this->authorize('view', $row);

        $row->load(['errors', 'mda', 'department', 'station', 'cadre', 'rank', 'salaryScale', 'matchedStaff', 'publishedStaff']);

        return Inertia::render('LegacyStaffImports/Rows/Show', [
            'batch' => [
                'id' => $batch->id,
                'status' => $batch->status,
                'source_table' => $batch->source_table,
            ],
            'row' => LegacyStaffImportRowResource::make($row)->resolve(),
            'summary' => $queryService->summarizeBatch($batch, $request->user()),
            'mappingOptions' => [
                'mdas' => Mda::query()->visibleToUser($request->user())->orderBy('name')->get(['id', 'code', 'name'])->toArray(),
                'departments' => Department::query()->orderBy('name')->get(['id', 'mda_id', 'name'])->toArray(),
                'stations' => Station::query()->orderBy('name')->get(['id', 'mda_id', 'name'])->toArray(),
                'cadres' => Cadre::query()->orderBy('name')->get(['id', 'department_id', 'salary_scale_id', 'name'])->toArray(),
                'ranks' => Rank::query()->orderBy('name')->get(['id', 'cadre_id', 'salary_scale_id', 'name', 'level'])->toArray(),
                'qualification_types' => QualificationType::query()->orderBy('name')->get(['id', 'code', 'name'])->toArray(),
            ],
            'can' => [
                'publish' => $request->user()->can('publish', $row),
                'resolve' => $request->user()->can('resolveMapping', $row),
                'ignoreWarnings' => $request->user()->can('ignoreWarning', $row),
            ],
        ]);
    }
}
