<?php

namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStaffQualificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $staff = $this->route('staff');

        return $staff && ($this->user()?->can('update', $staff) ?? false);
    }

    public function rules(): array
    {
        return [
            'qualification_type_id' => [
                'nullable',
                'integer',
                Rule::exists('qualification_types', 'id')->where(fn ($query) => $query->where('status', 'active')),
            ],
            'qualification_name' => ['nullable', 'string', 'max:255'],
            'highest_qualification_name' => ['nullable', 'string', 'max:255'],
            'specialization' => ['nullable', 'string', 'max:255'],
            'is_highest' => ['nullable', 'boolean'],
            'source' => ['nullable', 'string', 'max:50'],
        ];
    }
}
