<?php

namespace App\Http\Requests\ServiceReporting;

use Illuminate\Foundation\Http\FormRequest;

class AnalyticsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('view-service-reports');
    }

    public function rules(): array
    {
        return [
            'template_code' => ['required', 'string', 'exists:report_templates,code'],
            'indicator_code' => ['required', 'string'],
            'from' => ['nullable', 'date_format:Y-m'],
            'to' => ['nullable', 'date_format:Y-m'],
            'mda_id' => ['nullable', 'integer', 'exists:mdas,id'],
            'station_id' => ['nullable', 'integer', 'exists:stations,id'],
            'status' => ['nullable'],
        ];
    }
}
