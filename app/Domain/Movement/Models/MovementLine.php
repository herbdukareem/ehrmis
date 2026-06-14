<?php

namespace App\Domain\Movement\Models;

use App\Domain\Staff\Models\SalaryScale;
use App\Domain\Staff\Models\Staff;
use App\Domain\Staff\Models\StaffEmployment;
use App\Domain\Staff\Models\StaffSalaryPlacement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MovementLine extends Model
{
    protected $fillable = [
        'workbook_id',
        'staff_id',
        'current_employment_id',
        'current_salary_placement_id',
        'current_salary_scale_id',
        'proposed_salary_scale_id',
        'selection_state',
        'eligibility_status',
        'retirement_status',
        'retirement_month',
        'current_level',
        'current_step',
        'proposed_level',
        'proposed_step',
        'current_amounts',
        'proposed_amounts',
        'decision_trace',
        'calculation_source',
    ];

    protected function casts(): array
    {
        return [
            'retirement_month' => 'integer',
            'current_level' => 'integer',
            'current_step' => 'integer',
            'proposed_level' => 'integer',
            'proposed_step' => 'integer',
            'current_amounts' => 'array',
            'proposed_amounts' => 'array',
            'decision_trace' => 'array',
        ];
    }

    public function workbook(): BelongsTo
    {
        return $this->belongsTo(MovementWorkbook::class, 'workbook_id');
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function currentEmployment(): BelongsTo
    {
        return $this->belongsTo(StaffEmployment::class, 'current_employment_id');
    }

    public function currentSalaryPlacement(): BelongsTo
    {
        return $this->belongsTo(StaffSalaryPlacement::class, 'current_salary_placement_id');
    }

    public function currentSalaryScale(): BelongsTo
    {
        return $this->belongsTo(SalaryScale::class, 'current_salary_scale_id');
    }

    public function proposedSalaryScale(): BelongsTo
    {
        return $this->belongsTo(SalaryScale::class, 'proposed_salary_scale_id');
    }
}
