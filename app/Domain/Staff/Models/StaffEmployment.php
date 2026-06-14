<?php

namespace App\Domain\Staff\Models;

use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Mda;
use App\Domain\Organization\Models\Station;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffEmployment extends Model
{
    protected $fillable = [
        'staff_id',
        'mda_id',
        'department_id',
        'station_id',
        'location_name',
        'cadre_id',
        'rank_id',
        'staff_category',
        'initial_rank',
        'date_first_appointment',
        'date_last_promotion',
        'expected_retirement_date',
        'next_promotion_date',
        'employment_status',
        'is_current',
        'effective_from',
        'effective_to',
    ];

    protected function casts(): array
    {
        return [
            'date_first_appointment' => 'date',
            'date_last_promotion' => 'date',
            'expected_retirement_date' => 'date',
            'next_promotion_date' => 'date',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'is_current' => 'boolean',
        ];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function mda(): BelongsTo
    {
        return $this->belongsTo(Mda::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public function cadre(): BelongsTo
    {
        return $this->belongsTo(Cadre::class);
    }

    public function rank(): BelongsTo
    {
        return $this->belongsTo(Rank::class);
    }
}
