<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Synchronous Spreadsheet Import Runtime
    |--------------------------------------------------------------------------
    |
    | Reference-data imports complete in-request, while staff-list uploads can
    | stage in the background after the response is sent to avoid gateway
    | timeouts on large workbooks. These limits still apply to the underlying
    | import process itself.
    |
    */
    'max_execution_seconds' => (int) env('OPERATIONAL_IMPORT_MAX_EXECUTION_SECONDS', 900),
    'memory_limit' => env('OPERATIONAL_IMPORT_MEMORY_LIMIT', '512M'),
    'staff_list_background' => env('OPERATIONAL_IMPORT_STAFF_LIST_BACKGROUND', true),
    'staff_list_stale_after_seconds' => (int) env('OPERATIONAL_IMPORT_STAFF_LIST_STALE_AFTER_SECONDS', 900),
];
