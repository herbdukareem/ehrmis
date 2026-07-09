<?php

namespace App\Domain\Staff\Support;

use Illuminate\Support\Str;

class UnifiedQualificationCatalog
{
    /**
     * @return array<string, array{name: string, aliases: list<string>, description: string}>
     */
    public static function types(): array
    {
        return [
            'FSLC' => [
                'name' => 'FSLC',
                'aliases' => ['PRY CERT', 'PRIMARY CERT', 'PRIMARY CERTIFICATE', 'FIRST SCHOOL LEAVING CERTIFICATE'],
                'description' => 'First School Leaving Certificate',
            ],
            'SSCE' => [
                'name' => 'SSCE',
                'aliases' => ['S/CERT', 'S CERT', 'SCHOOL CERT', 'WAEC', 'NECO', 'GCE', 'O LEVEL', 'O/L CERT'],
                'description' => 'Senior Secondary School Certificate',
            ],
            'NCE' => [
                'name' => 'NCE',
                'aliases' => ['NIGERIA CERTIFICATE IN EDUCATION'],
                'description' => 'Nigeria Certificate in Education',
            ],
            'OND' => [
                'name' => 'OND',
                'aliases' => ['ND', 'NATIONAL DIPLOMA', 'ORDINARY NATIONAL DIPLOMA'],
                'description' => 'Ordinary National Diploma',
            ],
            'HND' => [
                'name' => 'HND',
                'aliases' => ['HIGHER NATIONAL DIPLOMA'],
                'description' => 'Higher National Diploma',
            ],
            'BSC_BA_BENG' => [
                'name' => 'BSc/BA/BEng',
                'aliases' => ['DEGREE', 'BSC', 'B.SC', 'BA', 'B.A', 'BENG', 'B.ENG', 'FIRST DEGREE', 'BACHELOR DEGREE', 'BSC RN RM'],
                'description' => 'Bachelor degree or equivalent first degree',
            ],
            'PGD' => [
                'name' => 'PGD',
                'aliases' => ['POST GRADUATE DIPLOMA', 'POSTGRADUATE DIPLOMA'],
                'description' => 'Postgraduate Diploma',
            ],
            'MSC_MA_MBA' => [
                'name' => 'MSc/MA/MBA',
                'aliases' => ['MASTERS', 'MASTER', 'MSC', 'M.SC', 'MA', 'M.A', 'MBA', 'M.B.A', 'MASTER DEGREE'],
                'description' => 'Master degree',
            ],
            'PHD' => [
                'name' => 'PhD',
                'aliases' => ['PH.D', 'DOCTORATE'],
                'description' => 'Doctorate degree',
            ],
            'PROFESSIONAL_FELLOWSHIP' => [
                'name' => 'Professional Fellowship',
                'aliases' => ['FELLOWSHIP', 'PROF FELLOWSHIP', 'PROFESSIONAL FELLOW'],
                'description' => 'Recognized professional fellowship',
            ],
            'A_L_CERT' => [
                'name' => 'A/L CERT',
                'aliases' => ['A/L', 'A LEVEL', 'A LEVEL CERT', 'ADVANCED LEVEL CERTIFICATE'],
                'description' => 'Advanced Level Certificate',
            ],
            'V_T' => [
                'name' => 'V/T',
                'aliases' => ['VT', 'VOCATIONAL', 'VOCATIONAL TRAINING', 'VOCATIONAL/TECHNICAL'],
                'description' => 'Vocational or technical certificate',
            ],
            'NO_CERT' => [
                'name' => 'NO CERT',
                'aliases' => ['NO CERTIFICATE', 'NONE', 'NO QUALIFICATION'],
                'description' => 'No certificate recorded',
            ],
        ];
    }

    /**
     * @return array<string, array<string, int>>
     */
    public static function ceilings(): array
    {
        return [
            'FSLC' => ['CH' => 3, 'GL' => 4, 'CM' => 0, 'SG' => 0],
            'SSCE' => ['CH' => 3, 'GL' => 4, 'CM' => 0, 'SG' => 0],
            'NCE' => ['CH' => 13, 'GL' => 14, 'CM' => 0, 'SG' => 0],
            'OND' => ['CH' => 13, 'GL' => 14, 'CM' => 0, 'SG' => 0],
            'HND' => ['CH' => 14, 'GL' => 15, 'CM' => 0, 'SG' => 5],
            'BSC_BA_BENG' => ['CH' => 15, 'GL' => 17, 'CM' => 7, 'SG' => 5],
            'PGD' => ['CH' => 15, 'GL' => 17, 'CM' => 7, 'SG' => 5],
            'MSC_MA_MBA' => ['CH' => 15, 'GL' => 17, 'CM' => 7, 'SG' => 5],
            'PHD' => ['CH' => 15, 'GL' => 17, 'CM' => 7, 'SG' => 5],
            'PROFESSIONAL_FELLOWSHIP' => ['CH' => 15, 'GL' => 17, 'CM' => 7, 'SG' => 5],
            'A_L_CERT' => ['CH' => 8, 'GL' => 9, 'CM' => 0, 'SG' => 0],
            'V_T' => ['CH' => 6, 'GL' => 7, 'CM' => 0, 'SG' => 0],
            'NO_CERT' => ['CH' => 3, 'GL' => 4, 'CM' => 0, 'SG' => 0],
        ];
    }

    /**
     * @return array<string, array{name: string, min_level: int, max_level: int, min_step: int, max_step: int}>
     */
    public static function salaryScales(): array
    {
        return [
            'CM' => ['name' => 'CONMESS', 'min_level' => 1, 'max_level' => 8, 'min_step' => 1, 'max_step' => 11],
            'CH' => ['name' => 'CONHESS', 'min_level' => 1, 'max_level' => 15, 'min_step' => 1, 'max_step' => 15],
            'GL' => ['name' => 'GRADE LEVEL', 'min_level' => 1, 'max_level' => 17, 'min_step' => 1, 'max_step' => 15],
            'SG' => ['name' => 'SPECIAL GRADE', 'min_level' => 1, 'max_level' => 5, 'min_step' => 1, 'max_step' => 9],
        ];
    }

    public static function canonicalCodeFor(?string $value): ?string
    {
        $lookupKey = self::lookupKey($value);

        if ($lookupKey === null) {
            return null;
        }

        foreach (self::types() as $code => $definition) {
            $candidates = array_merge([$code, $definition['name']], $definition['aliases']);

            foreach ($candidates as $candidate) {
                if (self::lookupKey($candidate) === $lookupKey || self::compactKey($candidate) === self::compactKey($value)) {
                    return $code;
                }
            }
        }

        return null;
    }

    public static function normalizeSalaryScaleCode(?string $value): ?string
    {
        $code = self::compactKey($value);

        return match ($code) {
            'CONHESS' => 'CH',
            'CONMESS' => 'CM',
            'GRADELEVEL' => 'GL',
            'SPECIALGRADE' => 'SG',
            default => $code,
        };
    }

    protected static function lookupKey(?string $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '') {
            return null;
        }

        $key = preg_replace('/[^A-Z0-9]+/i', ' ', $value) ?? '';
        $key = trim(preg_replace('/\s+/', ' ', $key) ?? '');

        return $key === '' ? null : Str::upper($key);
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
