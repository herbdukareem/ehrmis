<?php

namespace App\Http\Requests\Staff;

use Illuminate\Validation\Rule;

class StoreStaffRegistryRequest extends StoreStaffRequest
{
    public function authorize(): bool
    {
        if (! parent::authorize()) {
            return false;
        }

        return ! is_numeric($this->input('mda_id'))
            || ($this->user()->canAccessMda((int) $this->input('mda_id'))
                && (! $this->user()->hasStationScope()
                    || (int) $this->user()->station?->mda_id === (int) $this->input('mda_id')));
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('full_name')) {
            $this->merge(['full_name' => collect([$this->input('surname'), $this->input('first_name'), $this->input('middle_name')])
                ->filter(fn ($value) => is_string($value) && trim($value) !== '')
                ->map(fn ($value) => trim($value))->implode(' ')]);
        }
    }

    public function rules(): array
    {
        $mdaId = $this->integer('mda_id');
        $department = Rule::exists('departments', 'id')->where('mda_id', $mdaId);
        if ($this->user()->hasDepartmentRestrictedAccess()) {
            $department->whereIn('id', $this->user()->accessibleDepartmentIds()->all());
        }

        return [
            ...parent::rules(),
            'staff_number' => ['required', 'string', 'max:255', Rule::unique('staff', 'staff_number')->where('mda_id', $mdaId)],
            'date_of_birth' => ['nullable', 'date', 'before_or_equal:today'],
            'status' => ['required', Rule::in(['active', 'retired', 'duplicate', 'inactive'])],
            'is_contract_staff' => ['sometimes', 'boolean'],
            'employment.mda_id' => ['nullable', 'integer', Rule::in([$mdaId])],
            'employment.department_id' => [Rule::requiredIf($this->user()->hasDepartmentRestrictedAccess()), 'required_with:employment.cadre_id,employment.rank_id', 'nullable', 'integer', $department],
            'employment.station_id' => [Rule::requiredIf($this->user()->hasStationScope()), 'nullable', 'integer', Rule::exists('stations', 'id')->where('mda_id', $mdaId), ...($this->user()->hasStationScope() ? [Rule::in([$this->user()->station_id])] : [])],
            'employment.cadre_id' => ['required_with:employment.rank_id', 'nullable', 'integer', Rule::exists('cadres', 'id')->where('department_id', $this->input('employment.department_id'))],
            'employment.rank_id' => ['nullable', 'integer', Rule::exists('ranks', 'id')->where('cadre_id', $this->input('employment.cadre_id'))],
            'employment.employment_status' => ['nullable', Rule::in(['active', 'retired', 'inactive'])],
            'employment.date_first_appointment' => ['nullable', 'date', 'before_or_equal:today'],
            'employment.date_last_promotion' => ['nullable', 'date', 'before_or_equal:today'],
            'salary_placement.salary_scale_id' => ['nullable', 'required_with:salary_placement.level,salary_placement.step', 'integer', 'exists:salary_scales,id'],
            'salary_placement.level' => ['nullable', 'required_with:salary_placement.salary_scale_id,salary_placement.step', 'integer', 'min:1', 'max:30'],
            'salary_placement.step' => ['nullable', 'required_with:salary_placement.salary_scale_id,salary_placement.level', 'integer', 'min:1', 'max:30'],
        ];
    }
}
