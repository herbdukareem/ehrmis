<?php

namespace App\Domain\ExecutiveOffice\Services;

use App\Domain\ExecutiveOffice\Models\Office;
use App\Domain\ExecutiveOffice\Models\OfficeNotification;
use App\Domain\ExecutiveOffice\Models\OfficeRecord;
use App\Domain\ExecutiveOffice\OfficeCatalog;
use App\Models\User;

class OfficeNotificationService
{
    public function __construct(protected OfficeAccess $access) {}

    public function notify(Office $office, OfficeRecord $record, string $kind, string $occasion): void
    {
        $ids = $office->memberships()->active()->whereIn('role', ['executive', 'lead_pa', 'pa', 'action_officer', 'registry'])->pluck('user_id')
            ->merge($office->delegations()->active()->pluck('user_id'))->unique();
        foreach (User::query()->whereIn('id', $ids)->where('status', 'active')->get() as $user) {
            if (! $this->access->visible($user, $office)->whereKey($record->id)->exists()) {
                continue;
            }
            OfficeNotification::query()->firstOrCreate(['dedupe_key' => hash('sha256', implode(':', [$office->id, $record->id, $user->id, $kind, $occasion]))],
                ['mda_id' => $office->mda_id, 'office_id' => $office->id, 'record_id' => $record->id, 'user_id' => $user->id, 'kind' => $kind]);
        }
    }

    public function reminders(Office $office): int
    {
        $records = $office->records()->where('mda_id', $office->mda_id)->whereNull('archived_at')->whereNotIn('status', OfficeCatalog::CLOSED)
            ->where(fn ($q) => $q->where(fn ($r) => $r->where(fn ($s) => $s->where('status', 'confirmed')->orWhere(fn ($v) => $v->where('type', 'visitor')->where('status', 'expected')))->whereBetween('starts_at', [now(), now()->addMinutes($office->reminder_minutes)]))
                ->orWhere('due_at', '<=', now()->addMinutes($office->reminder_minutes)))->get();
        foreach ($records as $record) {
            $kind = $record->due_at && $record->due_at->isPast() ? 'overdue' : 'reminder';
            $this->notify($office, $record, $kind, (string) ($record->due_at ?? $record->starts_at));
        }

        return $records->count();
    }

    public function feed(User $user, Office $office): array
    {
        $this->access->require($user, $office, 'read');
        $visible = $this->access->visible($user, $office)->whereNull('archived_at')->select('id');

        return OfficeNotification::query()->where('mda_id', $office->mda_id)->where('office_id', $office->id)->where('user_id', $user->id)
            ->whereIn('record_id', $visible)->orderByDesc('id')->limit(100)->get()->map(function ($item) use ($user, $office) {
                $record = $this->access->visible($user, $office)->whereKey($item->record_id)->firstOrFail();

                return ['id' => $item->id, 'record_id' => $record->id, 'subject' => $record->subject, 'type' => $record->type, 'kind' => $item->kind, 'read_at' => $item->read_at];
            })->all();
    }

    public function read(User $user, Office $office, int $id): void
    {
        $this->access->require($user, $office, 'read');
        $row = OfficeNotification::query()->where('mda_id', $office->mda_id)->where('office_id', $office->id)->where('user_id', $user->id)->findOrFail($id);
        abort_unless($this->access->visible($user, $office)->whereKey($row->record_id)->exists(), 404);
        $row->update(['read_at' => now()]);
    }
}
