<?php

namespace App\Http\Controllers;

use App\Domain\Budget\Models\BudgetWorkbook;
use App\Domain\Budget\Services\BudgetGenerationService;
use App\Domain\Budget\Services\BudgetWorkbookWorkflowService;
use App\Domain\Movement\Models\MovementWorkbook;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Inertia\Inertia;
use Inertia\Response;

class BudgetWorkbookPageController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', BudgetWorkbook::class);

        $user = $request->user();
        $query = BudgetWorkbook::query()
            ->with(['mda', 'movementWorkbook', 'approvalWorkflow.steps'])
            ->latest('year');

        if (! $user->hasGlobalMdaAccess()) {
            $query->where('mda_id', $user->mda_id);
        }

        $workbooks = $query->get()->map(fn (BudgetWorkbook $workbook): array => [
            'id' => $workbook->id,
            'mda' => $workbook->mda?->only(['id', 'code', 'name']),
            'year' => $workbook->year,
            'status' => $workbook->status,
            'summary' => $workbook->summary ?? [],
            'movement_workbook_id' => $workbook->movement_workbook_id,
            'generated_at' => optional($workbook->generated_at)?->toDateTimeString(),
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

        $movementOptions = MovementWorkbook::query()
            ->when(! $user->hasGlobalMdaAccess(), fn ($query) => $query->where('mda_id', $user->mda_id))
            ->whereIn('status', ['approved', 'locked'])
            ->with('mda')
            ->latest('year')
            ->get()
            ->map(fn (MovementWorkbook $workbook): array => [
                'id' => $workbook->id,
                'label' => $workbook->mda?->code.' '.$workbook->year.' (#'.$workbook->id.')',
            ])
            ->values();

        return Inertia::render('Budgets/Index', [
            'workbooks' => $workbooks,
            'movementOptions' => $movementOptions,
        ]);
    }

    public function show(BudgetWorkbook $budgetWorkbook): Response
    {
        $this->authorize('view', $budgetWorkbook);
        $budgetWorkbook->load(['mda', 'approvalWorkflow.steps']);

        $lines = $budgetWorkbook->lines()
            ->with(['department', 'salaryScale'])
            ->orderBy('department_id')
            ->orderBy('salary_scale_id')
            ->orderBy('level')
            ->get()
            ->map(fn ($line): array => [
                'department' => $line->department?->name ?? 'Unassigned',
                'scale' => $line->salaryScale?->code,
                'level' => $line->level,
                'staff_count' => $line->staff_count,
                'retiring_count' => $line->retiring_count,
                'current_gross_total' => $line->current_gross_total,
                'proposed_gross_total' => $line->proposed_gross_total,
                'variance_total' => $line->variance_total,
            ])
            ->values();

        return Inertia::render('Budgets/Show', [
            'workbook' => [
                'id' => $budgetWorkbook->id,
                'mda' => $budgetWorkbook->mda?->only(['id', 'code', 'name']),
                'year' => $budgetWorkbook->year,
                'status' => $budgetWorkbook->status,
                'summary' => $budgetWorkbook->summary ?? [],
                'movement_workbook_id' => $budgetWorkbook->movement_workbook_id,
                'generated_at' => optional($budgetWorkbook->generated_at)?->toDateTimeString(),
                'approved_at' => optional($budgetWorkbook->approved_at)?->toDateTimeString(),
                'locked_at' => optional($budgetWorkbook->locked_at)?->toDateTimeString(),
                'approval_workflow' => $budgetWorkbook->approvalWorkflow ? [
                    'id' => $budgetWorkbook->approvalWorkflow->id,
                    'status' => $budgetWorkbook->approvalWorkflow->status,
                    'submitted_at' => $budgetWorkbook->approvalWorkflow->submitted_at?->toDateTimeString(),
                    'approved_at' => $budgetWorkbook->approvalWorkflow->approved_at?->toDateTimeString(),
                    'rejected_at' => $budgetWorkbook->approvalWorkflow->rejected_at?->toDateTimeString(),
                    'rejection_comment' => $budgetWorkbook->approvalWorkflow->rejection_comment,
                    'steps' => $budgetWorkbook->approvalWorkflow->steps->map(fn ($step): array => [
                        'step_no' => $step->step_no,
                        'status' => $step->status,
                        'reviewer_role' => $step->reviewer_role,
                        'acted_at' => $step->acted_at?->toDateTimeString(),
                        'comment' => $step->comment,
                    ])->values()->all(),
                ] : null,
            ],
            'lines' => $lines,
        ]);
    }

    public function store(Request $request, BudgetGenerationService $service): RedirectResponse
    {
        $this->authorize('create', BudgetWorkbook::class);

        $validated = $request->validate([
            'movement_workbook_id' => ['required', 'integer', 'exists:movement_workbooks,id'],
        ]);

        $movementWorkbook = MovementWorkbook::query()
            ->with(['summaries', 'mda'])
            ->findOrFail((int) $validated['movement_workbook_id']);

        abort_unless(
            $request->user()->hasGlobalMdaAccess() || (int) $request->user()->mda_id === (int) $movementWorkbook->mda_id,
            403
        );

        $budgetWorkbook = $service->generateFromMovementWorkbook($movementWorkbook, $request->user()?->id);

        return redirect()->route('budget-workbooks.show', $budgetWorkbook);
    }

    public function submitApproval(BudgetWorkbook $budgetWorkbook, Request $request, BudgetWorkbookWorkflowService $service): RedirectResponse
    {
        $this->authorize('approve', $budgetWorkbook);

        try {
            $service->submit($budgetWorkbook, $request->user());
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back();
    }

    public function approve(BudgetWorkbook $budgetWorkbook, Request $request, BudgetWorkbookWorkflowService $service): RedirectResponse
    {
        $this->authorize('approve', $budgetWorkbook);

        try {
            $service->approve($budgetWorkbook, $request->user());
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back();
    }

    public function reject(BudgetWorkbook $budgetWorkbook, Request $request, BudgetWorkbookWorkflowService $service): RedirectResponse
    {
        $this->authorize('approve', $budgetWorkbook);

        $validated = $request->validate([
            'comment' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $service->reject($budgetWorkbook, $request->user(), $validated['comment']);
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back();
    }

    public function lock(BudgetWorkbook $budgetWorkbook, Request $request, BudgetWorkbookWorkflowService $service): RedirectResponse
    {
        $this->authorize('approve', $budgetWorkbook);

        try {
            $service->lock($budgetWorkbook);
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back();
    }

    public function reopen(BudgetWorkbook $budgetWorkbook, Request $request, BudgetWorkbookWorkflowService $service): RedirectResponse
    {
        $this->authorize('approve', $budgetWorkbook);

        try {
            $service->reopen($budgetWorkbook);
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back();
    }
}
