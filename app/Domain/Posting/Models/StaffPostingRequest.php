<?php

namespace App\Domain\Posting\Models;

use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Mda;
use App\Domain\Organization\Models\Station;
use App\Domain\Staff\Models\Staff;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class StaffPostingRequest extends Model
{
    protected $fillable = [
        'staff_id',
        'request_number',
        'posting_type',
        'from_mda_id',
        'from_department_id',
        'from_station_id',
        'to_mda_id',
        'to_department_id',
        'to_station_id',
        'effective_date',
        'reason',
        'staff_snapshot',
        'status',
        'requested_by',
        'submitted_at',
        'issued_by',
        'issued_at',
        'effected_by',
        'effected_at',
    ];

    protected function casts(): array
    {
        return [
            'effective_date' => 'date',
            'staff_snapshot' => 'array',
            'submitted_at' => 'datetime',
            'issued_at' => 'datetime',
            'effected_at' => 'datetime',
        ];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function fromMda(): BelongsTo
    {
        return $this->belongsTo(Mda::class, 'from_mda_id');
    }

    public function toMda(): BelongsTo
    {
        return $this->belongsTo(Mda::class, 'to_mda_id');
    }

    public function fromDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'from_department_id');
    }

    public function toDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'to_department_id');
    }

    public function fromStation(): BelongsTo
    {
        return $this->belongsTo(Station::class, 'from_station_id');
    }

    public function toStation(): BelongsTo
    {
        return $this->belongsTo(Station::class, 'to_station_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(StaffPostingApproval::class, 'posting_request_id');
    }

    public function letter(): HasOne
    {
        return $this->hasOne(StaffPostingLetter::class, 'posting_request_id');
    }
}
