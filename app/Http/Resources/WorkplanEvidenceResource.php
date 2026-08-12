<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkplanEvidenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'evidence_type' => $this->evidence_type,
            'external_url' => $this->external_url,
            'notes' => $this->notes,
            'uploaded_by' => $this->uploadedBy?->only(['id', 'name']),
            'created_at' => $this->created_at?->toISOString(),
            'download_url' => $this->file_path ? route('api.workplan-evidence.download', $this->resource) : null,
        ];
    }
}
