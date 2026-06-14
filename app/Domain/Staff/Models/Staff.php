<?php

namespace App\Domain\Staff\Models;

use App\Domain\Legacy\Models\LegacyStaffImportRow;
use App\Domain\Organization\Models\Mda;
use App\Models\Concerns\HasMdaScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Staff extends Model
{
    use HasMdaScope, SoftDeletes;

    protected $fillable = [
        'mda_id',
        'staff_number',
        'legacy_staff_id',
        'legacy_master_staff_id',
        'legacy_cno',
        'legacy_psn',
        'legacy_cno_psn',
        'surname',
        'first_name',
        'middle_name',
        'full_name',
        'sex',
        'date_of_birth',
        'passport_path',
        'passport_mime_type',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
        ];
    }

    public function mda(): BelongsTo
    {
        return $this->belongsTo(Mda::class);
    }

    public function personalDetail(): HasOne
    {
        return $this->hasOne(StaffPersonalDetail::class);
    }

    public function employments(): HasMany
    {
        return $this->hasMany(StaffEmployment::class);
    }

    public function currentEmployment(): HasOne
    {
        return $this->hasOne(StaffEmployment::class)->where('is_current', true);
    }

    public function salaryPlacements(): HasMany
    {
        return $this->hasMany(StaffSalaryPlacement::class);
    }

    public function currentSalaryPlacement(): HasOne
    {
        return $this->hasOne(StaffSalaryPlacement::class)->where('is_current', true);
    }

    public function qualifications(): HasMany
    {
        return $this->hasMany(StaffQualification::class);
    }

    public function allowanceAssignments(): HasMany
    {
        return $this->hasMany(StaffAllowanceAssignment::class);
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(StaffStatusHistory::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(StaffDocument::class);
    }

    public function importRows(): HasMany
    {
        return $this->hasMany(LegacyStaffImportRow::class, 'published_staff_id');
    }
}
