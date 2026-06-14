<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LegacyStaffImportSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'row_status_counts' => $this['row_status_counts'] ?? [],
            'severity_counts' => $this['severity_counts'] ?? [],
            'issue_code_counts' => $this['issue_code_counts'] ?? [],
            'rows_staged' => (int) ($this['rows_staged'] ?? 0),
            'rows_published' => (int) ($this['rows_published'] ?? 0),
            'rows_publishable' => (int) ($this['rows_publishable'] ?? 0),
            'warnings_count' => (int) ($this['warnings_count'] ?? 0),
            'errors_count' => (int) ($this['errors_count'] ?? 0),
            'unresolved_reference_count' => (int) ($this['unresolved_reference_count'] ?? 0),
            'unresolved_call_allowance_count' => (int) ($this['unresolved_call_allowance_count'] ?? 0),
            'reference_issue_counts' => $this['reference_issue_counts'] ?? [],
        ];
    }
}
