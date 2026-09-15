<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

class ReportFormatter
{
    public const DATE_FORMAT = 'd-m-Y';
    public const DATETIME_FORMAT = 'd-m-Y, h:i A';
    public const EXCEL_DATE_FORMAT = 'dd-mm-yyyy';

    public static function cno(?string $cno, ?string $staffNumber): ?string
    {
        return filled($cno) ? trim($cno) : (filled($staffNumber) ? trim($staffNumber) : null);
    }

    public static function personName(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        $name = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        if (preg_match('/^[=+\-@]/', $name) === 1) {
            return $name;
        }

        return collect(preg_split('/(\s+)/u', $name, -1, PREG_SPLIT_DELIM_CAPTURE))
            ->map(fn (string $part): string => preg_match('/^\s+$/u', $part) === 1 ? $part : self::nameToken($part))
            ->implode('');
    }

    public static function date(DateTimeInterface|string|null $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        return ($value instanceof DateTimeInterface ? $value : CarbonImmutable::parse($value))->format(self::DATE_FORMAT);
    }

    protected static function nameToken(string $value): string
    {
        if (preg_match('/^([A-Za-z]\.)+$/', $value) === 1 || preg_match('/^[A-Za-z]$/', $value) === 1) {
            return mb_strtoupper($value);
        }

        return collect(preg_split('/([\'-])/u', mb_strtolower($value), -1, PREG_SPLIT_DELIM_CAPTURE))
            ->map(fn (string $part): string => in_array($part, ["'", '-'], true) ? $part : mb_convert_case($part, MB_CASE_TITLE, 'UTF-8'))
            ->implode('');
    }
}
