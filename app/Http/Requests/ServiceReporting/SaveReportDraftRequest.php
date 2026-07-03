<?php

namespace App\Http\Requests\ServiceReporting;

use Illuminate\Foundation\Http\FormRequest;

class SaveReportDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create-service-reports');
    }

    public function rules(): array
    {
        return [
            'values' => ['required', 'array'],
            'values.*.indicator_code' => ['required', 'string'],
            'values.*.value' => ['nullable'],
            'values.*.dimensions' => ['nullable', 'array'],
        ];
    }
}
