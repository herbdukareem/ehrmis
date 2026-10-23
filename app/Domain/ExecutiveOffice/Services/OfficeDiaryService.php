<?php

namespace App\Domain\ExecutiveOffice\Services;

use App\Domain\ExecutiveOffice\Models\Office;
use App\Domain\ExecutiveOffice\Models\OfficeRecord;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class OfficeDiaryService
{
    public function __construct(protected OfficeAccess $access) {}

    public function conflict(Office $office, OfficeRecord $record): bool
    {
        return $office->records()->where('mda_id', $office->mda_id)->whereIn('type', ['appointment', 'meeting'])
            ->where('status', 'confirmed')->whereNull('archived_at')->whereKeyNot($record->id)
            ->where('starts_at', '<', $record->ends_at)->where('ends_at', '>', $record->starts_at)->exists();
    }

    public function exceptional(Office $office, OfficeRecord $record): bool
    {
        if ($record->details['sensitive'] ?? false) {
            return true;
        }
        $start = $record->starts_at->copy()->timezone($office->timezone);
        $end = $record->ends_at->copy()->timezone($office->timezone);

        return ! in_array($start->dayOfWeekIso, $office->workdays ?? [1, 2, 3, 4, 5], true)
            || ! $start->isSameDay($end) || $start->format('H:i') < $office->workday_start || $end->format('H:i') > $office->workday_end;
    }

    public function confirm(User $actor, Office $office, OfficeRecord $record): void
    {
        if (($record->type === 'appointment' && ! $office->pa_confirms_routine) || $this->exceptional($office, $record)) {
            $this->access->require($actor, $office, 'decide');
        }
        if ($this->conflict($office, $record)) {
            throw ValidationException::withMessages(['starts_at' => 'The executive already has a confirmed engagement during this period.']);
        }
    }

    public function calendar(User $user, Office $office, string $date, string $view): array
    {
        $this->access->require($user, $office, 'read');
        abort_unless($this->access->team($user, $office), 403);
        $anchor = Carbon::parse($date, $office->timezone)->startOfDay();
        $start = match ($view) {
            'week' => $anchor->copy()->startOfWeek(), 'month' => $anchor->copy()->startOfMonth()->startOfWeek(), default => $anchor->copy()
        };
        $end = match ($view) {
            'week' => $start->copy()->addWeek(), 'month' => $anchor->copy()->endOfMonth()->endOfWeek()->addSecond(), default => $start->copy()->addDay()
        };
        $all = $office->records()->where('mda_id', $office->mda_id)->whereIn('type', ['appointment', 'meeting'])->whereNull('archived_at')
            ->whereNotIn('status', ['cancelled', 'declined'])->where('starts_at', '<', $end->copy()->utc())->where('ends_at', '>', $start->copy()->utc())->orderBy('starts_at')->get();
        $visible = $this->access->visible($user, $office)->whereIn('id', $all->pluck('id'))->pluck('id');

        return ['from' => $start->toDateString(), 'to' => $end->copy()->subSecond()->toDateString(), 'timezone' => $office->timezone,
            'events' => $all->filter(fn ($row) => $visible->contains($row->id) || $row->status === 'confirmed')->map(fn ($row) => [
                'id' => $visible->contains($row->id) ? $row->id : null,
                'subject' => $visible->contains($row->id) ? $row->subject : 'Unavailable',
                'status' => $row->status, 'starts_at' => $row->starts_at, 'ends_at' => $row->ends_at,
            ])->values()];
    }
}
