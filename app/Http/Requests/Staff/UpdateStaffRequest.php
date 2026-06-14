<?php

namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStaffRequest extends FormRequest
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
            'status_reason' => ['nullable', 'string', 'max:255'],
            'status_effective_from' => ['nullable', 'date'],

            'personal_detail.lga' => ['nullable', 'string', 'max:255'],
            'personal_detail.state_of_origin' => ['nullable', 'string', 'max:255'],
            'personal_detail.phone' => ['nullable', 'string', 'max:255'],
            'personal_detail.email' => ['nullable', 'email', 'max:255'],
            'personal_detail.address' => ['nullable', 'string'],
            'personal_detail.marital_status' => ['nullable', 'string', 'max:255'],
            'personal_detail.file_no' => ['nullable', 'string', 'max:255'],
        ];
    }
}
