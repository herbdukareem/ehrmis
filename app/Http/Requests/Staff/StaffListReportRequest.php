<?php

namespace App\Http\Requests\Staff;

use App\Domain\Staff\Services\StaffListReportService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StaffListReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('view-reports') || $this->user()?->can('export-reports');
    }

    public function rules(): array
    {
        return [
            'report_type' => ['sometimes', Rule::in(array_keys(StaffListReportService::REPORT_TYPES))],
            'search' => ['nullable', 'string', 'max:150'],
            'mda_id' => ['nullable', 'integer', 'min:1'],
            'department_id' => ['nullable', 'integer', 'min:1'],
            'station_id' => ['nullable', 'integer', 'min:1'],
            'cadre_id' => ['nullable', 'integer', 'min:1'],
            'station_ids' => ['sometimes', 'array', 'max:1000'],
            'station_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'cadre_ids' => ['sometimes', 'array', 'max:1000'],
            'cadre_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'columns' => ['sometimes', 'array', 'min:1', 'max:500'],
            'columns.*' => ['required', 'string', 'distinct', Rule::in(array_keys(app(StaffListReportService::class)->columns()))],
            'salary_scale_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', Rule::in(['active', 'retired', 'inactive', 'duplicate'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', Rule::in([10, 20, 50, 100, 250, 500])],
        ];
    }
}
