<?php

namespace App\Http\Requests\ServiceReporting;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReportTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('manage-report-templates');
    }

    public function rules(): array
    {
        return [
            'owner_mda_id' => ['nullable', 'integer', 'exists:mdas,id'],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:100', 'alpha_dash', 'unique:report_templates,code'],
            'description' => ['nullable', 'string'],
            'frequency' => ['required', Rule::in(['monthly', 'quarterly', 'yearly'])],
            'status' => ['sometimes', Rule::in(['draft', 'active', 'inactive', 'archived'])],
            'requires_approval' => ['boolean'],
            'submission_deadline_day' => ['nullable', 'integer', 'between:1,28'],
            'allow_late_submission' => ['boolean'],
            'sections' => ['sometimes', 'array'],
            'sections.*.title' => ['required_with:sections', 'string', 'max:255'],
            'sections.*.code' => ['required_with:sections', 'string', 'max:100', 'alpha_dash'],
            'sections.*.description' => ['nullable', 'string'],
            'sections.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'sections.*.indicators' => ['sometimes', 'array'],
            'sections.*.indicators.*.code' => ['required', 'string', 'max:100', 'alpha_dash'],
            'sections.*.indicators.*.label' => ['required', 'string', 'max:255'],
            'sections.*.indicators.*.description' => ['nullable', 'string'],
            'sections.*.indicators.*.value_type' => ['required', Rule::in(['integer', 'decimal', 'percentage', 'text', 'boolean'])],
            'sections.*.indicators.*.unit' => ['nullable', 'string', 'max:50'],
            'sections.*.indicators.*.is_required' => ['boolean'],
            'sections.*.indicators.*.is_computed' => ['boolean'],
            'sections.*.indicators.*.compute_formula' => ['nullable', 'array'],
            'sections.*.indicators.*.validation_rules' => ['nullable', 'array'],
            'sections.*.indicators.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'sections.*.indicators.*.status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'sections.*.indicators.*.dimensions' => ['sometimes', 'array'],
            'sections.*.indicators.*.dimensions.*.dimension_key' => ['required', 'string', 'max:100', 'alpha_dash'],
            'sections.*.indicators.*.dimensions.*.dimension_label' => ['required', 'string', 'max:255'],
            'sections.*.indicators.*.dimensions.*.dimension_values' => ['required', 'array', 'min:1'],
            'sections.*.indicators.*.dimensions.*.dimension_values.*' => ['required', 'string', 'max:100'],
            'sections.*.indicators.*.dimensions.*.is_required' => ['boolean'],
            'sections.*.indicators.*.dimensions.*.total_strategy' => ['nullable', Rule::in(['none', 'sum_values', 'manual'])],
            'sections.*.indicators.*.dimensions.*.sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
