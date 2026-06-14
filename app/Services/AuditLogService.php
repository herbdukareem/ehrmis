<?php

namespace App\Services;

use App\Domain\Audit\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class AuditLogService
{
    public function log(
        string $eventCode,
        Model|string|null $auditable = null,
        array $before = [],
        array $after = [],
        array $context = [],
    ): AuditLog {
        return AuditLog::query()->create([
            'actor_user_id' => Auth::id(),
            'event_code' => $eventCode,
            'auditable_type' => $auditable instanceof Model ? $auditable::class : (is_string($auditable) ? $auditable : null),
            'auditable_id' => $auditable instanceof Model ? $auditable->getKey() : null,
            'before_values' => $before ?: null,
            'after_values' => $after ?: null,
            'context' => $context ?: null,
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
            'occurred_at' => now(),
        ]);
    }

    public function logCreated(Model $model, array $context = []): AuditLog
    {
        return $this->log('created', $model, [], $model->toArray(), $context);
    }

    public function logUpdated(Model $model, array $before, array $context = []): AuditLog
    {
        return $this->log('updated', $model, $before, $model->fresh()?->toArray() ?? $model->toArray(), $context);
    }

    public function logDeleted(Model|string $auditable, array $before = [], array $context = []): AuditLog
    {
        return $this->log('deleted', $auditable, $before, [], $context);
    }

    public function logExport(string $reportCode, array $context = []): AuditLog
    {
        return $this->log('report.exported', $reportCode, [], [], $context);
    }
}
