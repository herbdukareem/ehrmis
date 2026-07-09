<?php

namespace App\Domain\Staff\Support;

use Illuminate\Support\Str;

class PromotionPolicyCatalog
{
    /**
     * @return list<array{scale_code: string, min_level: int, max_level: int, required_years: int}>
     */
    public static function defaults(): array
    {
        return [
            ['scale_code' => 'GL', 'min_level' => 2, 'max_level' => 6, 'required_years' => 2],
            ['scale_code' => 'GL', 'min_level' => 7, 'max_level' => 14, 'required_years' => 3],
            ['scale_code' => 'GL', 'min_level' => 15, 'max_level' => 17, 'required_years' => 4],
            ['scale_code' => 'CH', 'min_level' => 1, 'max_level' => 5, 'required_years' => 2],
            ['scale_code' => 'CH', 'min_level' => 6, 'max_level' => 13, 'required_years' => 3],
            ['scale_code' => 'CH', 'min_level' => 14, 'max_level' => 15, 'required_years' => 4],
            ['scale_code' => 'CM', 'min_level' => 1, 'max_level' => 4, 'required_years' => 3],
            ['scale_code' => 'CM', 'min_level' => 5, 'max_level' => 7, 'required_years' => 4],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function scaleOptions(): array
    {
        $definedScales = UnifiedQualificationCatalog::salaryScales();
        $options = [];

        foreach (self::defaults() as $policy) {
            $scaleCode = $policy['scale_code'];

            if (isset($options[$scaleCode])) {
                continue;
            }

            $options[$scaleCode] = $definedScales[$scaleCode]['name'] ?? $scaleCode;
        }

        return $options;
    }

    public static function normalizeScaleCode(?string $value): ?string
    {
        $code = self::compactKey($value);

        return match ($code) {
            'GRADELEVEL' => 'GL',
            'CONHESS' => 'CH',
            'CONMESS' => 'CM',
            'SPECIALGRADE' => 'SG',
            default => $code,
        };
    }

    protected static function compactKey(?string $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '') {
            return null;
        }

        $key = preg_replace('/[^A-Z0-9]+/i', '', $value) ?? '';

        return $key === '' ? null : Str::upper($key);
    }
}
