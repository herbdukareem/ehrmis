<?php

namespace App\Http\Controllers\Api;

use App\Domain\ExecutiveOffice\Exports\OfficeReportExport;
use App\Domain\ExecutiveOffice\Models\Office;
use App\Domain\ExecutiveOffice\OfficeCatalog;
use App\Domain\ExecutiveOffice\Services\OfficeAccess;
use App\Domain\ExecutiveOffice\Services\OfficeAdministrationService;
use App\Domain\ExecutiveOffice\Services\OfficeContinuityService;
use App\Domain\ExecutiveOffice\Services\OfficeDiaryService;
use App\Domain\ExecutiveOffice\Services\OfficeNotificationService;
use App\Domain\ExecutiveOffice\Services\OfficeRecordService;
use App\Domain\ExecutiveOffice\Services\OfficeReportingService;
use App\Domain\ExecutiveOffice\Services\OfficeWorkflowService;
use App\Domain\Module\Services\ModuleAccessService;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Mda;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;

class ExecutiveOfficeController extends Controller
{
    public function __construct(protected OfficeAccess $access) {}

    protected function office(Request $request): Office
    {
        $request->validate(['mda_id' => ['required', 'integer', 'min:1']]);
        abort_unless($request->user()->canAccessMda((int) $request->mda_id), 403);
        $office = Office::query()->where('mda_id', $request->mda_id)->firstOrFail();
        $this->access->assertEnabled($request->user(), $office);

        return $office;
    }

    public function context(Request $request, ModuleAccessService $modules)
    {
        $user = $request->user();
        abort_unless($user->can('view-executive-office') || $user->can('manage-executive-office') || $user->can('manage-mda-settings'), 403);
        $mdas = Mda::query()->visibleToUser($user)->orderBy('name')->get()->filter(fn ($mda) => $modules->mdaHasModule($mda->id, 'executive_office'));
        $rows = $mdas->map(function ($mda) use ($user) {
            $office = Office::query()->where('mda_id', $mda->id)->first();
            $configure = $this->access->configure($user, $mda->id);
            $abilities = $office ? $this->access->abilities($user, $office) : [];
            if (! $configure && ! $abilities) {
                return null;
            }

            return ['mda_id' => $mda->id, 'mda_name' => $mda->name, 'office' => $office, 'abilities' => $abilities,
                'can_configure' => $configure, 'roles' => $office ? $this->access->roles($user, $office) : [],
                'executive_content' => $office ? $this->access->executiveContent($user, $office) : false,
                'suggested_title' => $mda->setting?->head_title ?: 'Executive Secretary', 'suggested_prefix' => $mda->code.'/EO'];
        })->filter()->values();

        return response()->json(['data' => $rows, 'catalog' => ['types' => OfficeCatalog::TYPES, 'roles' => array_keys(OfficeCatalog::ROLES), 'states' => OfficeCatalog::STATES, 'classifications' => OfficeCatalog::CLASSIFICATIONS]]);
    }

    public function configure(Request $request, OfficeAdministrationService $service)
    {
        $request->validate(['mda_id' => ['required', 'integer', 'min:1']]);
        abort_unless(app(ModuleAccessService::class)->userCanAccessModule($request->user(), 'executive_office', (int) $request->mda_id), 403);

        return response()->json(['data' => $service->configure($request->user(), (int) $request->mda_id, $request->all())]);
    }

    public function team(Request $request)
    {
        $office = $this->office($request);
        abort_unless($this->access->configure($request->user(), $office->mda_id), 403);
        $users = User::query()->where('status', 'active')
            ->where(fn ($q) => $q->where('mda_id', $office->mda_id)->orWhereHas('accessScopes', fn ($s) => $s->where('mda_id', $office->mda_id)))
            ->get(['id', 'mda_id', 'name', 'email', 'user_type', 'status'])
            ->filter(fn ($user) => $user->canAccessMda($office->mda_id))->map->only(['id', 'name', 'email'])->values();

        return response()->json(['data' => $office->memberships()->with('user:id,name,email')->orderByDesc('id')->get(), 'users' => $users]);
    }

    public function assign(Request $request, OfficeAdministrationService $service)
    {
        return response()->json(['data' => $service->assign($request->user(), $this->office($request), $request->all())], 201);
    }

    public function revoke(Request $request, int $membership, OfficeAdministrationService $service)
    {
        $service->revoke($request->user(), $this->office($request), $membership);

        return response()->json(['message' => 'Office assignment revoked.']);
    }

    public function options(Request $request)
    {
        $office = $this->office($request);
        $this->access->require($request->user(), $office, 'read');
        $users = User::query()->where('status', 'active')->where('mda_id', $office->mda_id)
            ->when(! $this->access->team($request->user(), $office), fn ($q) => $q->whereKey($request->user()->id))->orderBy('name')->get(['id', 'name']);

        return response()->json(['users' => $users, 'departments' => Department::query()->where('mda_id', $office->mda_id)->orderBy('name')->get(['id', 'name'])]);
    }

    public function records(Request $request, OfficeRecordService $service)
    {
        $office = $this->office($request);
        $this->access->require($request->user(), $office, 'read');
        $filters = $request->validate(['type' => ['nullable', Rule::in(OfficeCatalog::TYPES)], 'search' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', 'string', 'max:40'], 'priority' => ['nullable', 'string'], 'archived' => ['nullable', 'boolean'],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'], 'page' => ['nullable', 'integer', 'min:1']]);
        $query = $this->access->visible($request->user(), $office)
            ->when($filters['type'] ?? null, fn ($q, $value) => $q->where('type', $value))
            ->when($filters['status'] ?? null, fn ($q, $value) => $q->where('status', $value))
            ->when($filters['priority'] ?? null, fn ($q, $value) => $q->where('priority', $value))
            ->when($filters['search'] ?? null, fn ($q, $value) => $q->where(fn ($r) => $r->where('subject', 'like', '%'.$value.'%')->orWhere('reference', 'like', '%'.$value.'%')))
            ->when($filters['from'] ?? null, fn ($q, $value) => $q->where('starts_at', '>=', $value))
            ->when($filters['to'] ?? null, fn ($q, $value) => $q->where('starts_at', '<=', $value));
        ($filters['archived'] ?? false) ? $query->whereNotNull('archived_at') : $query->whereNull('archived_at');
        $page = $query->orderByDesc('id')->paginate(25);

        return response()->json(['data' => $page->getCollection()->map(fn ($record) => $service->payload($request->user(), $office, $record)),
            'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    public function store(Request $request, OfficeRecordService $service)
    {
        $office = $this->office($request);
        $record = $service->create($request->user(), $office, $request->all());

        return response()->json(['data' => $service->payload($request->user(), $office, $record, true)], 201);
    }

    public function show(Request $request, int $record, OfficeRecordService $service)
    {
        $office = $this->office($request);

        return response()->json(['data' => $service->payload($request->user(), $office, $this->access->record($request->user(), $office, $record), true)]);
    }

    public function update(Request $request, int $record, OfficeRecordService $service)
    {
        $office = $this->office($request);
        $row = $service->update($request->user(), $office, $this->access->record($request->user(), $office, $record), $request->all());

        return response()->json(['data' => $service->payload($request->user(), $office, $row, true)]);
    }

    public function note(Request $request, int $record, OfficeRecordService $service)
    {
        $office = $this->office($request);
        $service->note($request->user(), $office, $this->access->record($request->user(), $office, $record), $request->all());

        return response()->json(['message' => 'Note recorded.'], 201);
    }

    public function attach(Request $request, int $record, OfficeRecordService $service)
    {
        $office = $this->office($request);
        $request->validate(['file' => ['required', 'file']]);

        return response()->json(['data' => $service->attach($request->user(), $office, $this->access->record($request->user(), $office, $record), $request->file('file'), $request->all())], 201);
    }

    public function download(Request $request, int $record, int $attachment)
    {
        $office = $this->office($request);
        $row = $this->access->record($request->user(), $office, $record);
        $file = $row->attachments()->where('mda_id', $office->mda_id)->findOrFail($attachment);
        abort_unless($this->access->canSeeVisibility($request->user(), $office, $file->visibility, $row)
            || ($file->visibility === 'recipients' && (int) $file->uploaded_by === (int) $request->user()->id), 404);
        abort_unless(Storage::disk('local')->exists($file->path), 404);

        return Storage::disk('local')->download($file->path, $file->name, ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    public function preview(Request $request, int $record, int $attachment)
    {
        $office = $this->office($request);
        $row = $this->access->record($request->user(), $office, $record);
        $file = $row->attachments()->where('mda_id', $office->mda_id)->findOrFail($attachment);
        abort_unless($this->access->canSeeVisibility($request->user(), $office, $file->visibility, $row)
            || ($file->visibility === 'recipients' && (int) $file->uploaded_by === (int) $request->user()->id), 404);
        abort_unless(in_array($file->mime_type, ['application/pdf', 'image/jpeg', 'image/png', 'text/plain'], true), 422, 'Download this document to open it.');
        abort_unless(Storage::disk('local')->exists($file->path), 404);

        return Storage::disk('local')->response($file->path, $file->name, ['Content-Type' => $file->mime_type, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store', 'Content-Security-Policy' => "default-src 'none'; sandbox"], 'inline');
    }

    public function link(Request $request, int $record, OfficeRecordService $service)
    {
        $office = $this->office($request);
        $data = $request->validate(['target_id' => ['required', 'integer']]);
        $service->link($request->user(), $office, $this->access->record($request->user(), $office, $record), $data['target_id']);

        return response()->json(['message' => 'Records linked.'], 201);
    }

    public function act(Request $request, int $record, OfficeWorkflowService $workflow, OfficeRecordService $records)
    {
        $office = $this->office($request);
        $row = $workflow->act($request->user(), $office, $this->access->record($request->user(), $office, $record), $request->all());

        return response()->json(['data' => $records->payload($request->user(), $office, $row, true)]);
    }

    public function directives(Request $request, int $record, OfficeWorkflowService $workflow)
    {
        $office = $this->office($request);
        $rows = $workflow->directives($request->user(), $office, $this->access->record($request->user(), $office, $record), $request->all());

        return response()->json(['data' => collect($rows)->map->only(['id', 'reference', 'subject', 'status'])], 201);
    }

    public function diary(Request $request, OfficeDiaryService $service)
    {
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d'], 'view' => ['required', Rule::in(['day', 'week', 'month'])]]);

        return response()->json(['data' => $service->calendar($request->user(), $this->office($request), $data['date'], $data['view'])]);
    }

    public function notifications(Request $request, OfficeNotificationService $service)
    {
        return response()->json(['data' => $service->feed($request->user(), $this->office($request))]);
    }

    public function readNotification(Request $request, int $notification, OfficeNotificationService $service)
    {
        $service->read($request->user(), $this->office($request), $notification);

        return response()->json(['message' => 'Notification marked as read.']);
    }

    public function dashboard(Request $request, OfficeReportingService $service)
    {
        return response()->json(['data' => $service->dashboard($request->user(), $this->office($request))]);
    }

    public function reports(Request $request, OfficeReportingService $service)
    {
        return response()->json($service->report($request->user(), $this->office($request), $this->reportFilters($request)));
    }

    protected function reportFilters(Request $request): array
    {
        return $request->validate(['type' => ['nullable', Rule::in(OfficeCatalog::TYPES)], 'status' => ['nullable', 'string', 'max:40'],
            'priority' => ['nullable', Rule::in(['normal', 'high', 'urgent'])], 'department_id' => ['nullable', 'integer'], 'assigned_to' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'], 'page' => ['nullable', 'integer', 'min:1']]);
    }

    public function exportReport(Request $request, OfficeReportingService $service)
    {
        $office = $this->office($request);
        $filters = $this->reportFilters($request);
        $service->query($request->user(), $office, $filters);
        app(AuditLogService::class)->logExport('executive_office.register', ['mda_id' => $office->mda_id, 'office_id' => $office->id]);

        return Excel::download(new OfficeReportExport($service, $request->user(), $office, $filters), 'executive-office-report-'.now()->format('Y-m-d-His').'.xlsx');
    }

    public function prepareBriefing(Request $request, OfficeReportingService $service)
    {
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d']]);

        return response()->json(['data' => $service->prepareBriefing($request->user(), $this->office($request), $data['date'])], 201);
    }

    public function printPack(Request $request, int $record, OfficeRecordService $service)
    {
        $office = $this->office($request);
        $row = $this->access->record($request->user(), $office, $record);
        abort_unless($this->access->team($request->user(), $office), 403);
        $data = ['office' => $office, 'mdaName' => Mda::query()->whereKey($office->mda_id)->value('name'), 'record' => $service->payload($request->user(), $office, $row, true)];
        $noteVisibilities = collect($data['record']['notes'])->pluck('visibility');
        $fileVisibilities = collect($data['record']['attachments'])->pluck('visibility');
        if ($noteVisibilities->merge($fileVisibilities)->contains('executive_only')) {
            $data['record']['confidentiality'] = 'executive_only';
        } elseif ($row->confidentiality === 'standard' && $noteVisibilities->merge($fileVisibilities)->contains('office')) {
            $data['record']['confidentiality'] = 'office_restricted';
        }
        app(AuditLogService::class)->logExport('executive_office.pack', ['mda_id' => $office->mda_id, 'record_id' => $row->id, 'version' => $row->version]);

        return Pdf::loadView('executive-office.pack', $data)->setPaper('a4')->download('office-pack-'.str_replace('/', '-', $row->reference).'.pdf');
    }

    public function delegations(Request $request)
    {
        $office = $this->office($request);
        $this->access->require($request->user(), $office, 'delegate');

        return response()->json(['data' => $office->delegations()->orderByDesc('id')->get(), 'abilities' => OfficeContinuityService::DELEGATABLE]);
    }

    public function delegate(Request $request, OfficeContinuityService $service)
    {
        return response()->json(['data' => $service->delegate($request->user(), $this->office($request), $request->all())], 201);
    }

    public function revokeDelegation(Request $request, int $delegation, OfficeContinuityService $service)
    {
        $service->revoke($request->user(), $this->office($request), $delegation);

        return response()->json(['message' => 'Delegation revoked.']);
    }

    public function handover(Request $request, OfficeContinuityService $service)
    {
        return response()->json(['data' => $service->handover($request->user(), $this->office($request), $request->all())]);
    }

    public function handoverPack(Request $request, OfficeContinuityService $service)
    {
        return response()->json(['data' => $service->prepareHandover($request->user(), $this->office($request))], 201);
    }
}
