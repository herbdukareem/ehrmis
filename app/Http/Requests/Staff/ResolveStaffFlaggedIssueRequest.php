<?php

namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;

class ResolveStaffFlaggedIssueRequest extends FormRequest
{
    public function authorize(): bool
    {
        $staff = $this->route('staff');

        return $staff && ($this->user()?->can('update', $staff) ?? false);
    }

    public function rules(): array
    {
        return [
            'date_of_birth' => ['nullable', 'date'],
            'cadre_id' => ['nullable', 'integer', 'exists:cadres,id'],
            'rank_id' => ['nullable', 'integer', 'exists:ranks,id'],
            'qualification_type_id' => ['nullable', 'integer', 'exists:qualification_types,id'],
            'allowances' => ['nullable', 'array'],
            'allowances.*.allowance_type_id' => ['required', 'integer', 'exists:allowance_types,id'],
            'allowances.*.is_eligible' => ['nullable', 'boolean'],
        ];
    }
}
