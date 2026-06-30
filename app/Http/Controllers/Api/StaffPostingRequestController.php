<?php

namespace App\Http\Controllers\Api;

use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Mda;
use App\Domain\Organization\Models\Station;
use App\Domain\Posting\Models\StaffPostingRequest;
use App\Domain\Posting\Services\StaffPostingWorkflowService;
use App\Domain\Staff\Models\Staff;
use App\Http\Controllers\Controller;
use App\Services\OfficialLetterPdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class StaffPostingRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', StaffPostingRequest::class);

        $query = StaffPostingRequest::query()->with($this->relations())->latest();
        if (! $request->user()->hasGlobalMdaAccess()) {
            $accessible = $request->user()->accessibleMdaIds()->all();
            $query->where(fn ($postingQuery) => $postingQuery
                ->whereIn('from_mda_id', $accessible)
                ->orWhereIn('to_mda_id', $accessible));
        }

        return response()->json([
            'data' => $query->limit(200)->get()->map(fn (StaffPostingRequest $posting): array => $this->payload($posting))->values(),
            'options' => $this->options($request),
        ]);
    }

    public function store(Request $request, StaffPostingWorkflowService $service): JsonResponse
    {
        $this->authorize('create', StaffPostingRequest::class);

        $validated = $request->validate([
            'staff_id' => ['required', 'integer', 'exists:staff,id'],
            'to_mda_id' => ['required', 'integer', 'exists:mdas,id'],
            'to_department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'to_station_id' => ['nullable', 'integer', 'exists:stations,id'],
            'effective_date' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $staff = Staff::query()->findOrFail((int) $validated['staff_id']);
        abort_unless($request->user()->canAccessMda((int) $staff->mda_id), 403);

        try {
            $posting = $service->create($validated, $request->user());
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['message' => 'Posting request created.', 'data' => $this->payload($posting)], 201);
    }

    public function show(StaffPostingRequest $postingRequest): JsonResponse
    {
        $this->authorize('view', $postingRequest);

        return response()->json(['data' => $this->payload($postingRequest->load($this->relations()))]);
    }

    public function submit(Request $request, StaffPostingRequest $postingRequest, StaffPostingWorkflowService $service): JsonResponse
    {
        $this->authorize('submit', $postingRequest);

        return $this->run(fn () => $service->submit($postingRequest, $request->user()), 'Posting request submitted.');
    }

    public function approveOrigin(Request $request, StaffPostingRequest $postingRequest, StaffPostingWorkflowService $service): JsonResponse
    {
        $this->authorize('approveOrigin', $postingRequest);

        return $this->run(fn () => $service->approveOrigin($postingRequest, $request->user(), $request->string('comment')->toString() ?: null), 'Origin MDA approval recorded.');
    }

    public function approveReceiving(Request $request, StaffPostingRequest $postingRequest, StaffPostingWorkflowService $service): JsonResponse
    {
        $this->authorize('approveReceiving', $postingRequest);

        return $this->run(fn () => $service->approveReceiving($postingRequest, $request->user(), $request->string('comment')->toString() ?: null), 'Receiving MDA approval recorded.');
    }

    public function approveFinal(Request $request, StaffPostingRequest $postingRequest, StaffPostingWorkflowService $service): JsonResponse
    {
        $this->authorize('approveFinal', $postingRequest);

        return $this->run(fn () => $service->approveFinal($postingRequest, $request->user(), $request->string('comment')->toString() ?: null), 'Final posting approval recorded.');
    }

    public function reject(Request $request, StaffPostingRequest $postingRequest, StaffPostingWorkflowService $service): JsonResponse
    {
        $this->authorize('reject', $postingRequest);
        $validated = $request->validate(['comment' => ['required', 'string', 'max:1000']]);

        return $this->run(fn () => $service->reject($postingRequest, $request->user(), $validated['comment']), 'Posting request rejected.');
    }

    public function issue(Request $request, StaffPostingRequest $postingRequest, StaffPostingWorkflowService $service): JsonResponse
    {
        $this->authorize('issue', $postingRequest);

        try {
            $service->issueLetter($postingRequest, $request->user());
            return response()->json([
                'message' => 'Posting letter issued.',
                'data' => $this->payload($postingRequest->fresh($this->relations())),
            ]);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function effect(Request $request, StaffPostingRequest $postingRequest, StaffPostingWorkflowService $service): JsonResponse
    {
        $this->authorize('effect', $postingRequest);

        return $this->run(fn () => $service->effect($postingRequest, $request->user()), 'Posting effected on staff record.');
    }

    public function letterPdf(StaffPostingRequest $postingRequest, OfficialLetterPdfService $pdfService): BinaryFileResponse
    {
        $this->authorize('issue', $postingRequest);

        $letter = $postingRequest->letter;
        abort_unless($letter, 404);

        if (! $letter->pdf_path || ! Storage::disk('local')->exists($letter->pdf_path)) {
            $letter = $pdfService->renderPostingLetter($letter);
        }

        return response()->file(Storage::disk('local')->path($letter->pdf_path), [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.str_replace('"', '', $letter->letter_number).'.pdf"',
        ]);
    }

    protected function run(callable $action, string $message): JsonResponse
    {
        try {
            return response()->json(['message' => $message, 'data' => $this->payload($action())]);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    protected function options(Request $request): array
    {
        $staffQuery = Staff::query()->with(['currentEmployment.department', 'currentEmployment.station'])->orderBy('full_name');
        if (! $request->user()->hasGlobalMdaAccess()) {
            $request->user()->scopeToAccessibleMdas($staffQuery, 'mda_id');
        }

        $departmentQuery = Department::query()->orderBy('name');
        $stationQuery = Station::query()->orderBy('name');
        if (! $request->user()->hasGlobalMdaAccess()) {
            $departmentQuery->forMdas($request->user()->accessibleMdaIds()->all());
            $stationQuery->forMdas($request->user()->accessibleMdaIds()->all());
        }

        return [
            'mdas' => Mda::query()->visibleToUser($request->user())->orderBy('name')->get(['id', 'code', 'name']),
            'staff' => $staffQuery->limit(200)->get(['id', 'mda_id', 'staff_number', 'full_name'])->map(fn (Staff $staff): array => [
                'id' => $staff->id,
                'mda_id' => $staff->mda_id,
                'staff_number' => $staff->staff_number,
                'full_name' => $staff->full_name,
                'department' => $staff->currentEmployment?->department?->name,
                'station' => $staff->currentEmployment?->station?->name,
            ])->values(),
            'departments' => $departmentQuery->get(['id', 'mda_id', 'name']),
            'stations' => $stationQuery->get(['id', 'mda_id', 'name']),
        ];
    }

    protected function payload(StaffPostingRequest $posting): array
    {
        return [
            'id' => $posting->id,
            'request_number' => $posting->request_number,
            'posting_type' => $posting->posting_type,
            'staff_id' => $posting->staff_id,
            'staff' => $posting->staff?->only(['id', 'staff_number', 'full_name', 'mda_id']),
            'from_mda' => $posting->fromMda?->only(['id', 'code', 'name']),
            'to_mda' => $posting->toMda?->only(['id', 'code', 'name']),
            'from_department' => $posting->fromDepartment?->only(['id', 'name']),
            'to_department' => $posting->toDepartment?->only(['id', 'name']),
            'from_station' => $posting->fromStation?->only(['id', 'name']),
            'to_station' => $posting->toStation?->only(['id', 'name']),
            'to_mda_id' => $posting->to_mda_id,
            'to_department_id' => $posting->to_department_id,
            'to_station_id' => $posting->to_station_id,
            'effective_date' => $posting->effective_date?->toDateString(),
            'reason' => $posting->reason,
            'staff_snapshot' => $posting->staff_snapshot,
            'status' => $posting->status,
            'submitted_at' => $posting->submitted_at?->toDateTimeString(),
            'issued_at' => $posting->issued_at?->toDateTimeString(),
            'effected_at' => $posting->effected_at?->toDateTimeString(),
            'approvals' => $posting->approvals?->map(fn ($approval): array => [
                'stage' => $approval->stage,
                'decision' => $approval->decision,
                'comment' => $approval->comment,
                'acted_at' => $approval->acted_at?->toDateTimeString(),
                'actor' => $approval->actor?->only(['id', 'name']),
            ])->values() ?? [],
            'letter' => $posting->letter ? [
                'letter_number' => $posting->letter->letter_number,
                'status' => $posting->letter->status,
                'printed_at' => $posting->letter->printed_at?->toDateTimeString(),
                'pdf_url' => route('api.posting-requests.letter-pdf', $posting, false),
            ] : null,
        ];
    }

    protected function relations(): array
    {
        return [
            'staff',
            'fromMda',
            'toMda',
            'fromDepartment',
            'toDepartment',
            'fromStation',
            'toStation',
            'approvals.actor',
            'letter',
        ];
    }
}
