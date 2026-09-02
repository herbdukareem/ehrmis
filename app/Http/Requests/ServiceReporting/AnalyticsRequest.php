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
            'report_style' => ['nullable', 'in:trend,template_table'],
            'indicator_code' => ['nullable', 'string'],
            'indicator_codes' => ['nullable', 'array', 'min:1', 'max:6'],
            'indicator_codes.*' => ['required', 'string', 'distinct'],
            'from' => ['nullable', 'date_format:Y-m'],
            'to' => ['nullable', 'date_format:Y-m'],
            'mda_id' => ['nullable', 'integer', 'exists:mdas,id'],
            'station_id' => ['nullable', 'integer', 'exists:stations,id'],
            'status' => ['nullable'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if ($this->input('report_style', 'trend') === 'template_table') {
                return;
            }

            if (blank($this->input('indicator_code')) && empty($this->input('indicator_codes'))) {
                $validator->errors()->add('indicator_codes', 'Select at least one indicator for charts and trends.');
            }
        });
    }
}
