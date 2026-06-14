<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LegacyStaffImportErrorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'field' => $this->field,
            'error_code' => $this->error_code,
            'message' => $this->message,
            'severity' => $this->severity,
            'resolved_at' => $this->resolved_at?->toDateTimeString(),
            'ignored_at' => $this->ignored_at?->toDateTimeString(),
            'resolution_notes' => $this->resolution_notes,
            'resolution_context' => $this->resolution_context,
        ];
    }
}
