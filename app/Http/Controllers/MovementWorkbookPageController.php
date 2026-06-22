<?php

namespace App\Http\Controllers;

use App\Domain\Movement\Models\MovementWorkbook;
use App\Domain\Movement\Services\MovementSheetGenerationService;
use App\Domain\Movement\Services\MovementWorkbookWorkflowService;
use App\Domain\Organization\Models\Mda;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Inertia\Inertia;
use Inertia\Response;

class MovementWorkbookPageController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', MovementWorkbook::class);

        $user = $request->user();
        $query = MovementWorkbook::query()->with(['mda', 'approvalWorkflow.steps'])->latest('year');

        if (! $user->hasGlobalMdaAccess()) {
            $user->scopeToAccessibleMdas($query, 'mda_id');
        }

        $workbooks = $query->get()->map(fn (MovementWorkbook $workbook): array => [
            'id' => $workbook->id,
            'mda' => $workbook->mda?->only(['id', 'code', 'name']),
            'year' => $workbook->year,
            'status' => $workbook->status,
            'summary' => $workbook->summary ?? [],
            'generated_at' => optional($workbook->generated_at)?->toDateTimeString(),
            'reviewed_at' => optional($workbook->reviewed_at)?->toDateTimeString(),
            'approved_at' => optional($workbook->approved_at)?->toDateTimeString(),
            'locked_at' => optional($workbook->locked_at)?->toDateTimeString(),
            'approval_workflow' => $workbook->approvalWorkflow ? [
                'id' => $workbook->approvalWorkflow->id,
                'status' => $workbook->approvalWorkflow->status,
                'submitted_at' => $workbook->approvalWorkflow->submitted_at?->toDateTimeString(),
                'approved_at' => $workbook->approvalWorkflow->approved_at?->toDateTimeString(),
                'rejected_at' => $workbook->approvalWorkflow->rejected_at?->toDateTimeString(),
                'rejection_comment' => $workbook->approvalWorkflow->rejection_comment,
                'steps' => $workbook->approvalWorkflow->steps->map(fn ($step): array => [
                    'step_no' => $step->step_no,
                    'status' => $step->status,
                    'reviewer_role' => $step->reviewer_role,
                    'acted_at' => $step->acted_at?->toDateTimeString(),
                    'comment' => $step->comment,
                ])->values()->all(),
            ] : null,
        ])->values();

        $mdaOptions = Mda::query()
            ->visibleToUser($user)
            ->orderBy('name')
            ->get(['id', 'code', 'name'])
            ->map(fn (Mda $mda): array => $mda->only(['id', 'code', 'name']))
            ->values();

        return Inertia::render('Movement/Index', [
            'workbooks' => $workbooks,
            'mdaOptions' => $mdaOptions,
            'defaultYear' => now()->year,
            'defaultMdaId' => $user->primaryAccessibleMdaId(),
        ]);
    }

    public function show(Request $request, MovementWorkbook $workbook): Response
    {
        $this->authorize('view', $workbook);
        $workbook->load(['mda', 'approvalWorkflow.steps']);

        $eligibilityStatus = $request->string('eligibility_status')->toString();
        $retirementStatus = $request->string('retirement_status')->toString();
        $selectionState = $request->string('selection_state')->toString();

        $linesQuery = $workbook->lines()
            ->with(['staff', 'currentEmployment.department', 'currentSalaryScale'])
            ->orderBy('staff_id');

        if ($eligibilityStatus !== '') {
            $linesQuery->where('eligibility_status', $eligibilityStatus);
        }

        if ($retirementStatus !== '') {
            $linesQuery->where('retirement_status', $retirementStatus);
        }

        if ($selectionState !== '') {
            $linesQuery->where('selection_state', $selectionState);
        }

        $lines = $linesQuery->limit(100)->get()->map(function ($line): array {
            return [
                'staff_number' => $line->staff?->staff_number,
                'full_name' => $line->staff?->full_name,
                'department' => $line->currentEmployment?->department?->name ?? 'Unassigned',
                'scale' => $line->currentSalaryScale?->code,
                'current_level' => $line->current_level,
                'proposed_level' => $line->proposed_level,
                'eligibility_status' => $line->eligibility_status,
                'retirement_status' => $line->retirement_status,
                'selection_state' => $line->selection_state,
                'current_gross' => $line->current_amounts['calculated_gross'] ?? null,
                'proposed_gross' => $line->proposed_amounts['calculated_gross'] ?? null,
            ];
        })->values();

        $summaries = $workbook->summaries()
            ->with(['department', 'salaryScale'])
            ->orderBy('department_id')
            ->orderBy('salary_scale_id')
            ->orderBy('level')
            ->get()
            ->map(fn ($summary): array => [
                'department' => $summary->department?->name ?? 'Unassigned',
                'scale' => $summary->salaryScale?->code,
                'level' => $summary->level,
                'staff_count' => $summary->staff_count,
                'due_count' => $summary->due_count,
                'retiring_count' => $summary->retiring_count,
                'retired_count' => $summary->retired_count,
                'blocked_count' => $summary->blocked_count,
                'current_gross_total' => $summary->current_gross_total,
                'proposed_gross_total' => $summary->proposed_gross_total,
                'variance_total' => $summary->variance_total,
            ])
            ->values();

        return Inertia::render('Movement/Show', [
            'workbook' => [
                'id' => $workbook->id,
                'mda' => $workbook->mda?->only(['id', 'code', 'name']),
                'year' => $workbook->year,
                'status' => $workbook->status,
                'summary' => $workbook->summary ?? [],
                'generated_at' => optional($workbook->generated_at)?->toDateTimeString(),
                'reviewed_at' => optional($workbook->reviewed_at)?->toDateTimeString(),
                'approved_at' => optional($workbook->approved_at)?->toDateTimeString(),
                'locked_at' => optional($workbook->locked_at)?->toDateTimeString(),
                'approval_workflow' => $workbook->approvalWorkflow ? [
                    'id' => $workbook->approvalWorkflow->id,
                    'status' => $workbook->approvalWorkflow->status,
                    'submitted_at' => $workbook->approvalWorkflow->submitted_at?->toDateTimeString(),
                    'approved_at' => $workbook->approvalWorkflow->approved_at?->toDateTimeString(),
                    'rejected_at' => $workbook->approvalWorkflow->rejected_at?->toDateTimeString(),
                    'rejection_comment' => $workbook->approvalWorkflow->rejection_comment,
                    'steps' => $workbook->approvalWorkflow->steps->map(fn ($step): array => [
                        'step_no' => $step->step_no,
                        'status' => $step->status,
                        'reviewer_role' => $step->reviewer_role,
                        'acted_at' => $step->acted_at?->toDateTimeString(),
                        'comment' => $step->comment,
                    ])->values()->all(),
                ] : null,
            ],
            'lines' => $lines,
            'summaries' => $summaries,
            'filters' => [
                'eligibility_status' => $eligibilityStatus,
                'retirement_status' => $retirementStatus,
                'selection_state' => $selectionState,
            ],
        ]);
    }

    public function store(Request $request, MovementSheetGenerationService $service): RedirectResponse
    {
        $this->authorize('create', MovementWorkbook::class);

        $validated = $request->validate([
            'mda_id' => ['required', 'integer', 'exists:mdas,id'],
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
        ]);

        abort_unless($request->user()->canAccessMda((int) $validated['mda_id']), 403);

        $workbook = $service->generateForMda((int) $validated['mda_id'], (int) $validated['year'], $request->user()?->id);

        return redirect()->route('movement-workbooks.show', $workbook);
    }

    public function review(MovementWorkbook $workbook, Request $request, MovementWorkbookWorkflowService $service): RedirectResponse
    {
        $this->authorize('review', $workbook);

        try {
            $service->markReviewed($workbook, $request->user());
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back();
    }

    public function approve(MovementWorkbook $workbook, Request $request, MovementWorkbookWorkflowService $service): RedirectResponse
    {
        $this->authorize('approve', $workbook);

        try {
            $service->approve($workbook, $request->user());
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back();
    }

    public function reject(MovementWorkbook $workbook, Request $request, MovementWorkbookWorkflowService $service): RedirectResponse
    {
        $this->authorize('approve', $workbook);

        $validated = $request->validate([
            'comment' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $service->reject($workbook, $request->user(), $validated['comment']);
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back();
    }

    public function lock(MovementWorkbook $workbook, Request $request, MovementWorkbookWorkflowService $service): RedirectResponse
    {
        $this->authorize('approve', $workbook);

        try {
            $service->lock($workbook);
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back();
    }

    public function reopen(MovementWorkbook $workbook, Request $request, MovementWorkbookWorkflowService $service): RedirectResponse
    {
        $this->authorize('approve', $workbook);

        try {
            $service->reopen($workbook);
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back();
    }
}
