<?php

namespace App\Http\Requests\ServiceReporting;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateReportTemplateRequest extends StoreReportTemplateRequest
{
    public function rules(): array
    {
        $templateId = $this->route('template')?->id;
        $rules = parent::rules();
        $rules['name'] = ['sometimes', 'string', 'max:255'];
        $rules['code'] = ['sometimes', 'string', 'max:100', 'alpha_dash', Rule::unique('report_templates', 'code')->ignore($templateId)];
        $rules['frequency'] = ['sometimes', Rule::in(['monthly', 'quarterly', 'yearly'])];

        return $rules;
    }
}
