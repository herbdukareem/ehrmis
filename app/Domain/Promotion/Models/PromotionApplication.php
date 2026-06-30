<?php

namespace App\Domain\Promotion\Models;

use App\Domain\Organization\Models\Mda;
use App\Domain\Staff\Models\Rank;
use App\Domain\Staff\Models\SalaryScale;
use App\Domain\Staff\Models\Staff;
use App\Models\Concerns\HasMdaScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PromotionApplication extends Model
{
    use HasMdaScope;

    protected $fillable = [
        'cycle_id',
        'mda_id',
        'staff_id',
        'application_number',
        'staff_number',
        'legacy_cno',
        'legacy_psn',
        'surname',
        'first_name',
        'middle_name',
        'email',
        'phone',
        'applicant_remarks',
        'current_snapshot',
        'current_rank_id',
        'current_salary_scale_id',
        'current_level',
        'current_step',
        'proposed_rank_id',
        'proposed_salary_scale_id',
        'proposed_level',
        'proposed_step',
        'status',
        'submitted_at',
        'screened_by',
        'screened_at',
        'sitting_id',
        'decision',
        'decision_remarks',
        'correction_notes',
        'decided_by',
        'decided_at',
        'letter_printed_at',
    ];

    protected function casts(): array
    {
        return [
            'current_snapshot' => 'array',
            'current_level' => 'integer',
            'current_step' => 'integer',
            'proposed_level' => 'integer',
            'proposed_step' => 'integer',
            'submitted_at' => 'datetime',
            'screened_at' => 'datetime',
            'decided_at' => 'datetime',
            'letter_printed_at' => 'datetime',
        ];
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(PromotionCycle::class, 'cycle_id');
    }

    public function mda(): BelongsTo
    {
        return $this->belongsTo(Mda::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function sitting(): BelongsTo
    {
        return $this->belongsTo(PromotionSitting::class, 'sitting_id');
    }

    public function currentRank(): BelongsTo
    {
        return $this->belongsTo(Rank::class, 'current_rank_id');
    }

    public function proposedRank(): BelongsTo
    {
        return $this->belongsTo(Rank::class, 'proposed_rank_id');
    }

    public function currentSalaryScale(): BelongsTo
    {
        return $this->belongsTo(SalaryScale::class, 'current_salary_scale_id');
    }

    public function proposedSalaryScale(): BelongsTo
    {
        return $this->belongsTo(SalaryScale::class, 'proposed_salary_scale_id');
    }

    public function screener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'screened_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(PromotionApplicationDocument::class, 'application_id');
    }

    public function decisionRecord(): HasOne
    {
        return $this->hasOne(PromotionSittingDecision::class, 'application_id');
    }

    public function letter(): HasOne
    {
        return $this->hasOne(PromotionLetter::class, 'application_id');
    }
}
