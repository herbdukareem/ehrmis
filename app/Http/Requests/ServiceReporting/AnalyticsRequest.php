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
            'indicator_code' => ['nullable', 'string', 'required_without:indicator_codes'],
            'indicator_codes' => ['nullable', 'array', 'min:1', 'max:6', 'required_without:indicator_code'],
            'indicator_codes.*' => ['required', 'string', 'distinct'],
            'from' => ['nullable', 'date_format:Y-m'],
            'to' => ['nullable', 'date_format:Y-m'],
            'mda_id' => ['nullable', 'integer', 'exists:mdas,id'],
            'station_id' => ['nullable', 'integer', 'exists:stations,id'],
            'status' => ['nullable'],
        ];
    }
}
