<?php

namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;

class StoreStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create-staff') ?? false;
    }

    public function rules(): array
    {
        return [
            'mda_id' => ['required', 'integer', 'exists:mdas,id'],
            'staff_number' => ['required', 'string', 'max:255'],
            'legacy_cno' => ['nullable', 'string', 'max:50'],
            'legacy_psn' => ['nullable', 'string', 'max:50'],
            'surname' => ['required', 'string', 'max:255'],
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'full_name' => ['required', 'string', 'max:255'],
            'sex' => ['nullable', 'in:male,female'],
            'date_of_birth' => ['nullable', 'date'],
            'status' => ['required', 'string', 'max:30'],

            'personal_detail.lga' => ['nullable', 'string', 'max:255'],
            'personal_detail.state_of_origin' => ['nullable', 'string', 'max:255'],
            'personal_detail.phone' => ['nullable', 'string', 'max:255'],
            'personal_detail.email' => ['nullable', 'email', 'max:255'],
            'personal_detail.address' => ['nullable', 'string'],
            'personal_detail.marital_status' => ['nullable', 'string', 'max:255'],
            'personal_detail.file_no' => ['nullable', 'string', 'max:255'],

            'employment.mda_id' => ['nullable', 'integer', 'exists:mdas,id'],
            'employment.department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'employment.station_id' => ['nullable', 'integer', 'exists:stations,id'],
            'employment.location_name' => ['nullable', 'string', 'max:255'],
            'employment.cadre_id' => ['nullable', 'integer', 'exists:cadres,id'],
            'employment.rank_id' => ['nullable', 'integer', 'exists:ranks,id'],
            'employment.staff_category' => ['nullable', 'string', 'max:255'],
            'employment.initial_rank' => ['nullable', 'string', 'max:255'],
            'employment.date_first_appointment' => ['nullable', 'date'],
            'employment.date_last_promotion' => ['nullable', 'date'],
            'employment.expected_retirement_date' => ['nullable', 'date'],
            'employment.next_promotion_date' => ['nullable', 'date'],
            'employment.employment_status' => ['nullable', 'string', 'max:30'],
            'employment.effective_from' => ['nullable', 'date'],

            'salary_placement.salary_scale_id' => ['nullable', 'integer', 'exists:salary_scales,id'],
            'salary_placement.level' => ['nullable', 'integer', 'min:1', 'max:30'],
            'salary_placement.step' => ['nullable', 'integer', 'min:1', 'max:30'],
            'salary_placement.effective_from' => ['nullable', 'date'],

            'qualification.qualification_type_id' => ['nullable', 'integer', 'exists:qualification_types,id'],
            'qualification.qualification_name' => ['nullable', 'string', 'max:255'],
            'qualification.highest_qualification_name' => ['nullable', 'string', 'max:255'],
            'qualification.specialization' => ['nullable', 'string', 'max:255'],
            'qualification.is_highest' => ['nullable', 'boolean'],

            'allowances' => ['nullable', 'array'],
            'allowances.*.allowance_type_id' => ['required_with:allowances', 'integer', 'exists:allowance_types,id'],
            'allowances.*.is_eligible' => ['nullable', 'boolean'],
            'allowances.*.source' => ['nullable', 'string', 'max:50'],
            'allowances.*.effective_from' => ['nullable', 'date'],
            'allowances.*.effective_to' => ['nullable', 'date'],
        ];
    }
}
