<?php

namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStaffAllowanceAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $staff = $this->route('staff');

        return $staff && ($this->user()?->can('updateAllowances', $staff) ?? false);
    }

    public function rules(): array
    {
        return [
            'assignments' => ['required', 'array'],
            'assignments.*.allowance_type_id' => ['required', 'integer', 'exists:allowance_types,id'],
            'assignments.*.is_eligible' => ['nullable', 'boolean'],
            'assignments.*.source' => ['nullable', 'string', 'max:50'],
            'assignments.*.effective_from' => ['nullable', 'date'],
            'assignments.*.effective_to' => ['nullable', 'date'],
        ];
    }
}
