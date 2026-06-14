<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Synchronous Spreadsheet Import Runtime
    |--------------------------------------------------------------------------
    |
    | Operational imports validate and stage every spreadsheet row before the
    | response is returned. Give large staff-list workbooks enough time and
    | memory without changing the limits for unrelated web requests.
    |
    */
    'max_execution_seconds' => (int) env('OPERATIONAL_IMPORT_MAX_EXECUTION_SECONDS', 900),
    'memory_limit' => env('OPERATIONAL_IMPORT_MEMORY_LIMIT', '512M'),
];
