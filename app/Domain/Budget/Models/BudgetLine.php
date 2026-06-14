<?php

namespace App\Domain\Budget\Models;

use App\Domain\Organization\Models\Department;
use App\Domain\Staff\Models\SalaryScale;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BudgetLine extends Model
{
    protected $fillable = [
        'workbook_id',
        'department_id',
        'salary_scale_id',
        'level',
        'staff_count',
        'retiring_count',
        'current_gross_total',
        'proposed_gross_total',
        'variance_total',
    ];

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'staff_count' => 'integer',
            'retiring_count' => 'integer',
            'current_gross_total' => 'decimal:2',
            'proposed_gross_total' => 'decimal:2',
            'variance_total' => 'decimal:2',
        ];
    }

    public function workbook(): BelongsTo
    {
        return $this->belongsTo(BudgetWorkbook::class, 'workbook_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function salaryScale(): BelongsTo
    {
        return $this->belongsTo(SalaryScale::class);
    }
}
