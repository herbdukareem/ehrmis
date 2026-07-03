<?php

namespace App\Domain\ServiceReporting\Models;

use App\Domain\Module\Models\Module;
use App\Domain\Organization\Models\Mda;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ReportTemplate extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'owner_mda_id',
        'module_id',
        'module_code',
        'name',
        'code',
        'description',
        'frequency',
        'status',
        'requires_approval',
        'submission_deadline_day',
        'allow_late_submission',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'requires_approval' => 'boolean',
            'allow_late_submission' => 'boolean',
            'submission_deadline_day' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function ownerMda(): BelongsTo
    {
        return $this->belongsTo(Mda::class, 'owner_mda_id');
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(ReportTemplateSection::class)->orderBy('sort_order')->orderBy('id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ReportTemplateAssignment::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(ReportSubmission::class);
    }
}
