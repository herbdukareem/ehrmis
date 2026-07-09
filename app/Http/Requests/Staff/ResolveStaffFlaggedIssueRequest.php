<?php

namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResolveStaffFlaggedIssueRequest extends FormRequest
{
    public function authorize(): bool
    {
        $staff = $this->route('staff');

        if (! $staff) {
            return false;
        }

        $user = $this->user();

        if (! $user?->can('update', $staff)) {
            return false;
        }

        if (($this->filled('cadre_id') || $this->filled('rank_id')) && ! $user->can('updateAppointment', $staff)) {
            return false;
        }

        if ($this->filled('allowances') && ! $user->can('updateAllowances', $staff)) {
            return false;
        }

        return true;
    }

    public function rules(): array
    {
        return [
            'date_of_birth' => ['nullable', 'date'],
            'cadre_id' => ['nullable', 'integer', 'exists:cadres,id'],
            'rank_id' => ['nullable', 'integer', 'exists:ranks,id'],
            'qualification_type_id' => [
                'nullable',
                'integer',
                Rule::exists('qualification_types', 'id')->where(fn ($query) => $query->where('status', 'active')),
            ],
            'allowances' => ['nullable', 'array'],
            'allowances.*.allowance_type_id' => ['required', 'integer', 'exists:allowance_types,id'],
            'allowances.*.is_eligible' => ['nullable', 'boolean'],
        ];
    }
}
