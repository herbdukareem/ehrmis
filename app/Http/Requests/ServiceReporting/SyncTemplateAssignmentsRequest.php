<?php

namespace App\Http\Requests\ServiceReporting;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SyncTemplateAssignmentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('assign-report-templates');
    }

    public function rules(): array
    {
        return [
            'assignments' => ['required', 'array'],
            'assignments.*.mda_id' => ['required', 'integer', 'exists:mdas,id'],
            'assignments.*.station_id' => ['nullable', 'integer', 'exists:stations,id'],
            'assignments.*.department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'assignments.*.facility_type' => ['nullable', 'string', 'max:100'],
            'assignments.*.required_from' => ['nullable', 'date'],
            'assignments.*.required_until' => ['nullable', 'date', 'after_or_equal:assignments.*.required_from'],
            'assignments.*.is_required' => ['boolean'],
            'assignments.*.status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ];
    }
}
