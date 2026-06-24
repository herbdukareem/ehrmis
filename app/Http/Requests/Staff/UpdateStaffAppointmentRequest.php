<?php

namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStaffAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $staff = $this->route('staff');

        return $staff && ($this->user()?->can('updateAppointment', $staff) ?? false);
    }

    public function rules(): array
    {
        return [
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'station_id' => ['nullable', 'integer', 'exists:stations,id'],
            'location_name' => ['nullable', 'string', 'max:255'],
            'cadre_id' => ['nullable', 'integer', 'exists:cadres,id'],
            'rank_id' => ['nullable', 'integer', 'exists:ranks,id'],
            'staff_category' => ['nullable', 'string', 'max:255'],
            'initial_rank' => ['nullable', 'string', 'max:255'],
            'date_first_appointment' => ['nullable', 'date'],
            'date_last_promotion' => ['nullable', 'date'],
            'expected_retirement_date' => ['nullable', 'date'],
            'next_promotion_date' => ['nullable', 'date'],
            'employment_status' => ['required', 'string', 'max:30'],
            'effective_from' => ['nullable', 'date'],
            'salary_scale_id' => ['nullable', 'integer', 'exists:salary_scales,id', 'required_with:level,step'],
            'level' => ['nullable', 'integer', 'min:1', 'max:30', 'required_with:salary_scale_id,step'],
            'step' => ['nullable', 'integer', 'min:1', 'max:30', 'required_with:salary_scale_id,level'],
        ];
    }
}
