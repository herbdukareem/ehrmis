<?php

namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStaffSalaryPlacementRequest extends FormRequest
{
    public function authorize(): bool
    {
        $staff = $this->route('staff');

        return $staff && ($this->user()?->can('updateAppointment', $staff) ?? false);
    }

    public function rules(): array
    {
        return [
            'salary_scale_id' => ['required', 'integer', 'exists:salary_scales,id'],
            'level' => ['required', 'integer', 'min:1', 'max:30'],
            'step' => ['required', 'integer', 'min:1', 'max:30'],
            'effective_from' => ['nullable', 'date'],
            'source' => ['nullable', 'string', 'max:50'],
        ];
    }
}
