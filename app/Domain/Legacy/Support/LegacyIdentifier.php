<?php

namespace App\Domain\Legacy\Support;

class LegacyIdentifier
{
    /**
     * Placeholder values that appear in legacy extracts but should not be used
     * as real identity keys during matching.
     */
    protected const PLACEHOLDERS = [
        '0',
        '-',
        '--',
        'N/A',
        'NA',
        'NIL',
        'NONE',
        'NOT APPLICABLE',
        'NULL',
    ];

    public static function normalize(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim(preg_replace('/\s+/', ' ', (string) $value) ?? '');

        if ($normalized === '') {
            return null;
        }

        return in_array(strtoupper($normalized), self::PLACEHOLDERS, true) ? null : $normalized;
    }
}
