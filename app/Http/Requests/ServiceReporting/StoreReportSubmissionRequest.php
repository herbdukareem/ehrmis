<?php

namespace App\Http\Requests\ServiceReporting;

use Illuminate\Foundation\Http\FormRequest;

class StoreReportSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create-service-reports');
    }

    public function rules(): array
    {
        return [
            'template_id' => ['required', 'integer', 'exists:report_templates,id'],
            'mda_id' => ['required', 'integer', 'exists:mdas,id'],
            'station_id' => ['nullable', 'integer', 'exists:stations,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'period' => ['required', 'string', 'max:20'],
        ];
    }
}
