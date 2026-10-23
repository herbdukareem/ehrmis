<?php

namespace App\Domain\ExecutiveOffice\Services;

use App\Domain\ExecutiveOffice\Models\Office;
use App\Domain\ExecutiveOffice\OfficeCatalog;
use App\Domain\Organization\Models\Department;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

class OfficeReportingService
{
    public function __construct(protected OfficeAccess $access, protected OfficeRecordService $records) {}

    public function dashboard(User $actor, Office $office, ?string $date = null): array
    {
        $this->access->require($actor, $office, 'read');
        $start = Carbon::parse($date ?? now($office->timezone)->toDateString(), $office->timezone)->startOfDay()->utc();
        $end = $start->copy()->timezone($office->timezone)->addDay()->utc();
        $base = $this->access->visible($actor, $office)->whereNull('archived_at');
        $active = (clone $base)->whereNotIn('status', OfficeCatalog::CLOSED);
        $queues = [
            'schedule' => (clone $base)->whereIn('type', ['appointment', 'meeting'])->whereNotIn('status', ['cancelled', 'declined'])->where('starts_at', '>=', $start)->where('starts_at', '<', $end)->orderBy('starts_at'),
            'decisions' => (clone $active)->whereIn('status', ['awaiting_decision', 'minutes_submitted', 'response_submitted'])->orderBy('created_at'),
            'urgent' => (clone $active)->where('priority', 'urgent')->orderBy('due_at'),
            'overdue' => (clone $active)->where('due_at', '<', now())->orderBy('due_at'),
            'visitors' => (clone $active)->where('type', 'visitor')->where(fn ($q) => $q->where('status', 'arrived')->orWhere(fn ($r) => $r->where('status', 'expected')->where('starts_at', '>=', $start)->where('starts_at', '<', $end)))->orderBy('starts_at'),
            'preparation' => (clone $active)->whereIn('status', ['received', 'draft', 'requested', 'returned'])->orderBy('created_at'),
        ];
        $counts = [];
        $rows = [];
        foreach ($queues as $key => $query) {
            $counts[$key] = (clone $query)->count();
            $rows[$key] = $query->limit(20)->get()->map(fn ($record) => $this->records->payload($actor, $office, $record));
        }

        return ['role' => in_array('decide', $this->access->abilities($actor, $office), true) ? 'Executive' : 'Office coordination', 'date' => $start->copy()->timezone($office->timezone)->toDateString(), 'counts' => $counts, 'queues' => $rows];
    }

    public function query(User $actor, Office $office, array $filters): Builder
    {
        $this->access->require($actor, $office, 'report');
        $query = $this->access->visible($actor, $office);
        foreach (['type', 'status', 'priority', 'department_id', 'assigned_to'] as $key) {
            if (! empty($filters[$key])) {
                $query->where($key, $filters[$key]);
            }
        }
        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['from'], $office->timezone)->startOfDay()->utc());
        }
        if (! empty($filters['to'])) {
            $query->where('created_at', '<', Carbon::parse($filters['to'], $office->timezone)->addDay()->startOfDay()->utc());
        }

        return $query->orderBy('id');
    }

    public function report(User $actor, Office $office, array $filters): array
    {
        $query = $this->query($actor, $office, $filters);
        $total = (clone $query)->count();
        $closed = (clone $query)->whereNotNull('closed_at')->whereIn('status', ['completed', 'dispatched', 'departed', 'approved', 'published'])->get(['created_at', 'closed_at']);
        $byDepartment = (clone $query)->reorder()->select('department_id')->selectRaw('COUNT(*) as total')->groupBy('department_id')->get();
        $departments = Department::query()->where('mda_id', $office->mda_id)->pluck('name', 'id');
        $page = $query->paginate(25);

        return ['summary' => ['total' => $total, 'completed' => $closed->count(), 'overdue' => $this->query($actor, $office, $filters)->whereNull('closed_at')->where('due_at', '<', now())->count(),
            'average_completion_hours' => $closed->isEmpty() ? null : round($closed->avg(fn ($row) => $row->created_at->diffInSeconds($row->closed_at) / 3600), 1)],
            'by_department' => $byDepartment->map(fn ($group) => ['id' => $group->department_id ?? 0, 'department' => $departments[$group->department_id] ?? 'Not specified', 'total' => $group->total]),
            'data' => $page->getCollection()->map(fn ($record) => $this->records->payload($actor, $office, $record)),
            'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]];
    }

    public function prepareBriefing(User $actor, Office $office, string $date)
    {
        $this->access->require($actor, $office, 'brief');
        $dashboard = $this->dashboard($actor, $office, $date);
        $ids = collect($dashboard['queues'])->flatMap(fn ($rows) => $rows->pluck('id'))->unique();
        $classification = $this->access->visible($actor, $office)->whereIn('id', $ids)->where('confidentiality', 'executive_only')->exists() ? 'executive_only' : 'office_restricted';
        $sections = [];
        foreach ($dashboard['queues'] as $key => $rows) {
            $sections[] = ucfirst($key).' ('.$dashboard['counts'][$key].')'."\n".$rows->map(fn ($row) => $row['reference'].' — '.$row['subject'])->implode("\n");
        }

        return $this->records->create($actor, $office, ['type' => 'briefing', 'subject' => 'Daily executive briefing — '.$date, 'priority' => 'normal', 'confidentiality' => $classification,
            'details' => ['briefing_date' => $date, 'summary' => implode("\n\n", $sections)]]);
    }
}
