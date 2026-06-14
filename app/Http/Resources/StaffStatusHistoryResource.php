<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StaffStatusHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'reason' => $this->reason,
            'effective_from' => optional($this->effective_from)?->toDateString(),
            'metadata' => $this->metadata ?? [],
            'created_at' => optional($this->created_at)?->toDateTimeString(),
        ];
    }
}
