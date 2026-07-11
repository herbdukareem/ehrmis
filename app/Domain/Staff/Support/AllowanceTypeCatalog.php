<?php

namespace App\Domain\Staff\Support;

use Illuminate\Support\Str;

class AllowanceTypeCatalog
{
    /**
     * @return array<int, array{code: string, name: string, description: string}>
     */
    public static function definitions(): array
    {
        return [
            ['code' => 'shift', 'name' => 'Shift Allowance', 'description' => 'Expected from the `shift_` staff-list import column and legacy shift allowance fields.'],
            ['code' => 'hazard', 'name' => 'Hazard Allowance', 'description' => 'Expected from the `hazard_` staff-list import column and legacy hazard allowance fields.'],
            ['code' => 'teaching', 'name' => 'Teaching Allowance', 'description' => 'Expected from the `teaching_` staff-list import column and legacy teaching allowance fields.'],
            ['code' => 'specialty', 'name' => 'Specialty Allowance', 'description' => 'Expected from the `specialist_` staff-list import column and legacy specialist allowance fields.'],
            ['code' => 'rural', 'name' => 'Rural Allowance', 'description' => 'Expected from the `rural_` staff-list import column and legacy rural allowance fields.'],
            ['code' => 'call_doctor', 'name' => 'Call Allowance - Doctor', 'description' => 'Resolved from the `call_` staff-list import column when doctor call eligibility is detected.'],
            ['code' => 'call_pharm_lab', 'name' => 'Call Allowance - Pharmacy/Lab', 'description' => 'Resolved from the `call_` staff-list import column when pharmacy or lab call eligibility is detected.'],
            ['code' => 'call_opt_odd', 'name' => 'Call Allowance - Optometry/ODD', 'description' => 'Resolved from the `call_` staff-list import column when optometry or ODD call eligibility is detected.'],
            ['code' => 'call_nurse_others', 'name' => 'Call Allowance - Nurse/Others', 'description' => 'Resolved from the `call_` staff-list import column when nursing or other call eligibility is detected.'],
            ['code' => 'domestic', 'name' => 'Domestic Allowance', 'description' => 'Special Grade allowance for domestic support.'],
            ['code' => 'entertainment', 'name' => 'Entertainment Allowance', 'description' => 'Special Grade allowance for official entertainment responsibilities.'],
            ['code' => 'newspaper', 'name' => 'Newspaper Allowance', 'description' => 'Special Grade allowance for newspaper and publication costs.'],
            ['code' => 'personal_assistant', 'name' => 'Personal Assistant Allowance', 'description' => 'Special Grade allowance for personal assistant support.'],
            ['code' => 'utility', 'name' => 'Utility Allowance', 'description' => 'Special Grade allowance for official utility costs.'],
            ['code' => 'vehicle_maintenance', 'name' => 'Vehicle Maintenance Allowance', 'description' => 'Special Grade allowance for vehicle maintenance costs.'],
        ];
    }

    /**
     * @return array{code: string, name: string, description: string}
     */
    public static function definitionFor(string $code): array
    {
        $normalizedCode = Str::lower(trim($code));

        foreach (self::definitions() as $definition) {
            if ($definition['code'] === $normalizedCode) {
                return $definition;
            }
        }

        return [
            'code' => $normalizedCode,
            'name' => Str::of($normalizedCode)->replace('_', ' ')->title()->append(' Allowance')->toString(),
            'description' => 'Auto-provisioned from staff import publication.',
        ];
    }
}
