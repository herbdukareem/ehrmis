<?php

namespace App\Domain\Legacy\Models;

use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Mda;
use App\Domain\Organization\Models\Station;
use App\Domain\Staff\Models\Cadre;
use App\Domain\Staff\Models\Rank;
use App\Domain\Staff\Models\SalaryScale;
use App\Domain\Staff\Models\Staff;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LegacyStaffImportRow extends Model
{
    protected $fillable = [
        'batch_id',
        'legacy_staff_id',
        'legacy_master_staff_id',
        'mda_id',
        'staff_number',
        'legacy_cno',
        'legacy_psn',
        'legacy_cno_psn',
        'full_name',
        'raw_payload',
        'normalized_payload',
        'dedupe_key',
        'status',
        'matched_staff_id',
        'published_staff_id',
        'department_id',
        'department_name',
        'station_id',
        'station_name',
        'cadre_id',
        'cadre_name',
        'rank_id',
        'rank_name',
        'salary_scale_id',
        'salary_scale_code',
        'level',
        'step',
    ];

    protected function casts(): array
    {
        return [
            'raw_payload' => 'array',
            'normalized_payload' => 'array',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(LegacyStaffImportBatch::class, 'batch_id');
    }

    public function matchedStaff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'matched_staff_id');
    }

    public function mda(): BelongsTo
    {
        return $this->belongsTo(Mda::class, 'mda_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class, 'station_id');
    }

    public function cadre(): BelongsTo
    {
        return $this->belongsTo(Cadre::class, 'cadre_id');
    }

    public function rank(): BelongsTo
    {
        return $this->belongsTo(Rank::class, 'rank_id');
    }

    public function salaryScale(): BelongsTo
    {
        return $this->belongsTo(SalaryScale::class, 'salary_scale_id');
    }

    public function publishedStaff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'published_staff_id');
    }

    public function errors(): HasMany
    {
        return $this->hasMany(LegacyStaffImportError::class, 'row_id');
    }
}
