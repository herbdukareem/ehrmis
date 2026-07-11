<?php

namespace App\Http\Controllers\Api;

use App\Domain\Organization\Models\Mda;
use App\Domain\Promotion\Models\PromotionApplication;
use App\Domain\Promotion\Models\PromotionCycle;
use App\Domain\Promotion\Models\PromotionSitting;
use App\Domain\Promotion\Services\PromotionWorkflowService;
use App\Services\OfficialLetterPdfService;
use App\Domain\Staff\Models\Rank;
use App\Domain\Staff\Models\SalaryScale;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PromotionWorkflowController extends Controller
{
    public function publicOptions(): JsonResponse
    {
        return response()->json([
            'data' => [
                'cycles' => PromotionCycle::query()
                    ->where('status', 'open')
                    ->orderByDesc('year')
                    ->get(['id', 'mda_id', 'title', 'year', 'opens_at', 'closes_at']),
                'mdas' => Mda::query()->orderBy('name')->get(['id', 'code', 'name']),
            ],
        ]);
    }

    public function publicSubmit(Request $request, PromotionWorkflowService $service): JsonResponse
    {
        $validated = $request->validate([
            'cycle_id' => ['required', 'integer', 'exists:promotion_cycles,id'],
            'mda_id' => ['required', 'integer', 'exists:mdas,id'],
            'staff_number' => ['nullable', 'string', 'max:100', 'required_without_all:legacy_cno,legacy_psn'],
            'legacy_cno' => ['nullable', 'string', 'max:50', 'required_without_all:staff_number,legacy_psn'],
            'legacy_psn' => ['nullable', 'string', 'max:50', 'required_without_all:staff_number,legacy_cno'],
            'surname' => ['required', 'string', 'max:255'],
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'applicant_remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $application = $service->submitApplication(PromotionCycle::query()->findOrFail((int) $validated['cycle_id']), $validated);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Promotion application submitted.',
            'data' => [
                'application_number' => $application->application_number,
                'status' => $application->status,
            ],
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PromotionCycle::class);

        $query = PromotionCycle::query()->with(['mda'])->withCount(['applications', 'sittings'])->orderByDesc('year');
        if (! $request->user()->hasGlobalMdaAccess()) {
            $accessible = $request->user()->accessibleMdaIds()->all();
            $query->where(fn ($cycleQuery) => $cycleQuery->whereNull('mda_id')->orWhereIn('mda_id', $accessible));
        }

        return response()->json([
            'data' => $query->get()->map(fn (PromotionCycle $cycle): array => $this->cyclePayload($cycle)),
            'options' => [
                'mdas' => Mda::query()->visibleToUser($request->user())->orderBy('name')->get(['id', 'code', 'name']),
            ],
        ]);
    }

    public function store(Request $request, PromotionWorkflowService $service): JsonResponse
    {
        $this->authorize('create', PromotionCycle::class);

        $validated = $request->validate([
            'mda_id' => ['nullable', 'integer', 'exists:mdas,id'],
            'title' => ['required', 'string', 'max:255'],
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'opens_at' => ['nullable', 'date'],
            'closes_at' => ['nullable', 'date', 'after_or_equal:opens_at'],
            'status' => ['nullable', Rule::in(['draft', 'open', 'closed'])],
        ]);

        if (isset($validated['mda_id'])) {
            abort_unless($request->user()->canAccessMda((int) $validated['mda_id']), 403);
        }

        return response()->json([
            'message' => 'Promotion cycle created.',
            'data' => $this->cyclePayload($service->createCycle($validated, $request->user())),
        ], 201);
    }

    public function show(Request $request, PromotionCycle $cycle): JsonResponse
    {
        $this->authorize('view', $cycle);

        $cycle->load([
            'mda',
            'applications.mda',
            'applications.staff',
            'applications.proposedRank',
            'applications.proposedSalaryScale',
            'applications.sitting',
            'applications.letter',
            'sittings.mda',
            'sittings.approvalWorkflow.steps',
        ]);

        return response()->json([
            'data' => [
                ...$this->cyclePayload($cycle),
                'applications' => $cycle->applications
                    ->filter(fn (PromotionApplication $application): bool => $request->user()->canAccessMda($application->mda_id))
                    ->map(fn (PromotionApplication $application): array => $this->applicationPayload($application))
                    ->values(),
                'sittings' => $cycle->sittings
                    ->filter(fn (PromotionSitting $sitting): bool => $request->user()->canAccessMda($sitting->mda_id))
                    ->map(fn (PromotionSitting $sitting): array => $this->sittingPayload($sitting))
                    ->values(),
            ],
            'options' => $this->options($request),
        ]);
    }

    public function screen(Request $request, PromotionApplication $application, PromotionWorkflowService $service): JsonResponse
    {
        $this->authorize('screen', $application);

        $validated = $request->validate([
            'staff_id' => ['nullable', 'integer', 'exists:staff,id'],
            'current_rank_id' => ['nullable', 'integer', 'exists:ranks,id'],
            'current_salary_scale_id' => ['nullable', 'integer', 'exists:salary_scales,id'],
            'current_level' => ['nullable', 'integer', 'min:1', 'max:20'],
            'current_step' => ['nullable', 'integer', 'min:1', 'max:20'],
            'proposed_rank_id' => ['nullable', 'integer', 'exists:ranks,id'],
            'proposed_salary_scale_id' => ['nullable', 'integer', 'exists:salary_scales,id'],
            'proposed_level' => ['nullable', 'integer', 'min:1', 'max:20'],
            'proposed_step' => ['nullable', 'integer', 'min:1', 'max:20'],
            'status' => ['nullable', Rule::in(['screened', 'listed_for_sitting'])],
        ]);

        try {
            $application = $service->screen($application, $validated, $request->user());
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['message' => 'Promotion application screened.', 'data' => $this->applicationPayload($application)]);
    }

    public function storeSitting(Request $request, PromotionCycle $cycle, PromotionWorkflowService $service): JsonResponse
    {
        $this->authorize('create', PromotionCycle::class);

        $validated = $request->validate([
            'mda_id' => ['required', 'integer', 'exists:mdas,id'],
            'title' => ['required', 'string', 'max:255'],
            'sitting_date' => ['required', 'date'],
            'panel_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        abort_unless($request->user()->can('manage-promotion-sittings') && $request->user()->canAccessMda((int) $validated['mda_id']), 403);

        try {
            $sitting = $service->createSitting($cycle, $validated, $request->user());
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['message' => 'Promotion sitting created.', 'data' => $this->sittingPayload($sitting)], 201);
    }

    public function showSitting(PromotionSitting $sitting): JsonResponse
    {
        $this->authorize('view', $sitting);

        $sitting->load([
            'cycle',
            'mda',
            'applications.staff',
            'applications.proposedRank',
            'applications.proposedSalaryScale',
            'applications.letter',
            'decisions.application',
            'approvalWorkflow.steps',
        ]);

        return response()->json(['data' => $this->sittingPayload($sitting) + [
            'applications' => $sitting->applications->map(fn (PromotionApplication $application): array => $this->applicationPayload($application))->values(),
        ]]);
    }

    public function decide(Request $request, PromotionSitting $sitting, PromotionWorkflowService $service): JsonResponse
    {
        $this->authorize('decide', $sitting);

        $validated = $request->validate([
            'application_id' => ['required', 'integer', 'exists:promotion_applications,id'],
            'decision' => ['required', Rule::in(['approved', 'approved_with_corrections', 'rejected'])],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'correction_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $application = PromotionApplication::query()->findOrFail((int) $validated['application_id']);
        $this->authorize('decide', $application);

        try {
            $application = $service->decide($sitting, $application, $validated, $request->user());
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['message' => 'Promotion decision saved.', 'data' => $this->applicationPayload($application)]);
    }

    public function completeSitting(Request $request, PromotionSitting $sitting, PromotionWorkflowService $service): JsonResponse
    {
        $this->authorize('manage', $sitting);

        return $this->run(fn () => $service->completeSitting($sitting, $request->user()), 'Promotion sitting completed.');
    }

    public function submitPrintApproval(Request $request, PromotionSitting $sitting, PromotionWorkflowService $service): JsonResponse
    {
        $this->authorize('manage', $sitting);

        return $this->run(fn () => $service->submitPrintApproval($sitting, $request->user()), 'Promotion sitting submitted for print approval.');
    }

    public function approvePrint(Request $request, PromotionSitting $sitting, PromotionWorkflowService $service): JsonResponse
    {
        $this->authorize('approvePrint', $sitting);

        return $this->run(fn () => $service->approvePrint($sitting, $request->user(), $request->string('comment')->toString() ?: null), 'Promotion letter printing authorized.');
    }

    public function rejectPrint(Request $request, PromotionSitting $sitting, PromotionWorkflowService $service): JsonResponse
    {
        $this->authorize('approvePrint', $sitting);
        $validated = $request->validate(['comment' => ['required', 'string', 'max:1000']]);

        return $this->run(fn () => $service->rejectPrint($sitting, $request->user(), $validated['comment']), 'Promotion print approval rejected.');
    }

    public function printLetter(Request $request, PromotionApplication $application, PromotionWorkflowService $service): JsonResponse
    {
        $this->authorize('print', $application);

        return $this->run(fn () => $service->printLetter($application, $request->user()), 'Promotion letter printed.');
    }

    public function letterPdf(PromotionApplication $application, OfficialLetterPdfService $pdfService): BinaryFileResponse
    {
        $this->authorize('print', $application);

        $letter = $application->letter;
        abort_unless($letter, 404);

        if (! $letter->pdf_path || ! Storage::disk('local')->exists($letter->pdf_path)) {
            $letter = $pdfService->renderPromotionLetter($letter);
        }

        return response()->file(Storage::disk('local')->path($letter->pdf_path), [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.str_replace('"', '', $letter->letter_number).'.pdf"',
        ]);
    }

    protected function run(callable $action, string $message): JsonResponse
    {
        try {
            return response()->json(['message' => $message, 'data' => $action()]);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    protected function options(Request $request): array
    {
        return [
            'mdas' => Mda::query()->visibleToUser($request->user())->orderBy('name')->get(['id', 'code', 'name']),
            'ranks' => Rank::query()
                ->visibleToUser($request->user())
                ->with(['cadre.department:id,mda_id', 'salaryScale:id,code,name'])
                ->orderBy('name')
                ->get(['id', 'cadre_id', 'salary_scale_id', 'name', 'level'])
                ->map(fn (Rank $rank): array => [
                    'id' => $rank->id,
                    'mda_id' => $rank->cadre?->department?->mda_id,
                    'cadre_id' => $rank->cadre_id,
                    'salary_scale_id' => $rank->salary_scale_id,
                    'name' => $rank->name,
                    'level' => $rank->level,
                ]),
            'salary_scales' => SalaryScale::query()->orderBy('code')->get(['id', 'code', 'name']),
        ];
    }

    protected function cyclePayload(PromotionCycle $cycle): array
    {
        return [
            'id' => $cycle->id,
            'mda' => $cycle->mda?->only(['id', 'code', 'name']),
            'title' => $cycle->title,
            'year' => $cycle->year,
            'opens_at' => $cycle->opens_at?->toDateString(),
            'closes_at' => $cycle->closes_at?->toDateString(),
            'status' => $cycle->status,
            'applications_count' => $cycle->applications_count ?? $cycle->applications()->count(),
            'sittings_count' => $cycle->sittings_count ?? $cycle->sittings()->count(),
        ];
    }

    protected function applicationPayload(PromotionApplication $application): array
    {
        return [
            'id' => $application->id,
            'cycle_id' => $application->cycle_id,
            'mda_id' => $application->mda_id,
            'mda' => $application->mda?->only(['id', 'code', 'name']),
            'staff_id' => $application->staff_id,
            'staff_number' => $application->staff_number,
            'application_number' => $application->application_number,
            'full_name' => trim($application->surname.' '.$application->first_name.' '.$application->middle_name),
            'legacy_cno' => $application->legacy_cno,
            'legacy_psn' => $application->legacy_psn,
            'current_snapshot' => $application->current_snapshot,
            'proposed_rank_id' => $application->proposed_rank_id,
            'proposed_rank' => $application->proposedRank?->only(['id', 'name', 'level']),
            'proposed_salary_scale_id' => $application->proposed_salary_scale_id,
            'proposed_salary_scale' => $application->proposedSalaryScale?->only(['id', 'code', 'name']),
            'proposed_level' => $application->proposed_level,
            'proposed_step' => $application->proposed_step,
            'status' => $application->status,
            'decision' => $application->decision,
            'decision_remarks' => $application->decision_remarks,
            'correction_notes' => $application->correction_notes,
            'sitting_id' => $application->sitting_id,
            'sitting_title' => $application->sitting?->title,
            'letter' => $application->letter ? [
                'letter_number' => $application->letter->letter_number,
                'status' => $application->letter->status,
                'effective_date' => $application->letter->effective_date?->toDateString(),
                'printed_at' => $application->letter->printed_at?->toDateTimeString(),
                'pdf_url' => route('api.promotion-applications.letter-pdf', $application, false),
            ] : null,
            'submitted_at' => $application->submitted_at?->toDateTimeString(),
        ];
    }

    protected function sittingPayload(PromotionSitting $sitting): array
    {
        return [
            'id' => $sitting->id,
            'cycle_id' => $sitting->cycle_id,
            'mda_id' => $sitting->mda_id,
            'mda' => $sitting->mda?->only(['id', 'code', 'name']),
            'title' => $sitting->title,
            'sitting_date' => $sitting->sitting_date?->toDateString(),
            'panel_notes' => $sitting->panel_notes,
            'status' => $sitting->status,
            'completed_at' => $sitting->completed_at?->toDateTimeString(),
            'print_authorized_at' => $sitting->print_authorized_at?->toDateTimeString(),
            'approval_workflow' => $sitting->approvalWorkflow,
            'decisions_count' => $sitting->decisions_count ?? $sitting->decisions()->count(),
        ];
    }
}
