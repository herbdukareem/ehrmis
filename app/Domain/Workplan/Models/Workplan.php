<?php

namespace App\Domain\Workplan\Models;

use App\Domain\Approval\Models\ApprovalWorkflow;
use App\Domain\Organization\Models\Mda;
use App\Enums\WorkplanStatus;
use App\Models\Concerns\HasMdaScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class Workplan extends Model
{
    use HasMdaScope;

    protected $fillable = [
        'mda_id',
        'year',
        'revision_no',
        'supersedes_workplan_id',
        'title',
        'document_classification',
        'description',
        'overall_goal',
        'strategic_directions',
        'planning_assumptions',
        'status',
        'prepared_by',
        'prepared_by_label',
        'amendment_reason',
        'summary',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'revision_no' => 'integer',
            'status' => WorkplanStatus::class,
            'strategic_directions' => 'array',
            'planning_assumptions' => 'array',
            'summary' => 'array',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'activated_at' => 'datetime',
            'closed_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }

    public function mda(): BelongsTo { return $this->belongsTo(Mda::class); }
    public function objectives(): HasMany { return $this->hasMany(WorkplanObjective::class)->orderBy('sort_order')->orderBy('id'); }
    public function preparedBy(): BelongsTo { return $this->belongsTo(User::class, 'prepared_by'); }
    public function submittedBy(): BelongsTo { return $this->belongsTo(User::class, 'submitted_by'); }
    public function approvedBy(): BelongsTo { return $this->belongsTo(User::class, 'approved_by'); }
    public function activatedBy(): BelongsTo { return $this->belongsTo(User::class, 'activated_by'); }
    public function closedBy(): BelongsTo { return $this->belongsTo(User::class, 'closed_by'); }
    public function supersedes(): BelongsTo { return $this->belongsTo(self::class, 'supersedes_workplan_id'); }
    public function revisions(): HasMany { return $this->hasMany(self::class, 'supersedes_workplan_id'); }
    public function supersededBy(): BelongsTo { return $this->belongsTo(self::class, 'superseded_by_workplan_id'); }
    public function approvalWorkflow(): MorphOne { return $this->morphOne(ApprovalWorkflow::class, 'subject'); }
    public function isEditable(): bool { return in_array($this->status, [WorkplanStatus::DRAFT, WorkplanStatus::RETURNED], true); }
}
