<?php

namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStaffEmploymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $staff = $this->route('staff');

        return $staff && ($this->user()?->can('update', $staff) ?? false);
    }

    public function rules(): array
    {
        return [
            'mda_id' => ['required', 'integer', 'exists:mdas,id'],
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
        ];
    }
}
