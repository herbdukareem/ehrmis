<?php

namespace App\Domain\Legacy\Services;

use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class LegacyDateParser
{
    /**
     * @return array{value: ?string, warning: ?string}
     */
    public function parse(mixed $value, string $field): array
    {
        $string = is_scalar($value) ? trim((string) $value) : null;

        if ($string === null || $string === '' || in_array($string, ['0000-00-00', '0000-00-00 00:00:00'], true)) {
            return ['value' => null, 'warning' => null];
        }

        if (is_numeric($value) && (float) $value >= 1 && (float) $value <= 2958465) {
            try {
                return [
                    'value' => Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))->toDateString(),
                    'warning' => null,
                ];
            } catch (\Throwable) {
                // Fall through to the supported formatted-date checks.
            }
        }

        $formats = [
            'Y-m-d',
            'Y-m-d H:i:s',
            'm/d/Y',
            'm/d/y',
            'n/j/Y',
            'n/j/y',
            'n-j-Y',
            'n-j-y',
            'd/m/Y',
            'd/m/y',
            'j/n/Y',
            'j/n/y',
            'd-m-Y',
            'd-m-y',
            'j-n-Y',
            'j-n-y',
        ];

        foreach ($formats as $format) {
            try {
                $date = \DateTime::createFromFormat('!'.$format, $string);
                $errors = \DateTime::getLastErrors();

                $hasErrors = is_array($errors)
                    && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0);

                if ($date !== false && ! $hasErrors && $date->format($format) === $string) {
                    return ['value' => Carbon::instance($date)->toDateString(), 'warning' => null];
                }
            } catch (\Throwable) {
                // Try the next supported format.
            }
        }

        return [
            'value' => null,
            'warning' => 'Could not confidently parse '.$field.' value `'.$string.'`.',
        ];
    }
}
