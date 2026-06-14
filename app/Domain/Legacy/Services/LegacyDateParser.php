<?php

namespace App\Domain\Legacy\Services;

use Carbon\Carbon;

class LegacyDateParser
{
    /**
     * @return array{value: ?string, warning: ?string}
     */
    public function parse(mixed $value, string $field): array
    {
        $string = is_string($value) ? trim($value) : null;

        if ($string === null || $string === '' || in_array($string, ['0000-00-00', '0000-00-00 00:00:00'], true)) {
            return ['value' => null, 'warning' => null];
        }

        $formats = [
            'Y-m-d',
            'Y-m-d H:i:s',
            'd/m/Y',
            'd/m/y',
            'd-m-Y',
            'd-m-y',
            'm/d/Y',
            'm/d/y',
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
