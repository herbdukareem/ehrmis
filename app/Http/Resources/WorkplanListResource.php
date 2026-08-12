<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkplanListResource extends JsonResource
{
    public function toArray(Request $request): array { return ['id' => $this->id, 'mda' => $this->mda?->only(['id','code','name']), 'year' => $this->year, 'revision_no' => $this->revision_no, 'title' => $this->title, 'status' => $this->status?->value, 'updated_at' => $this->updated_at?->toISOString(), 'can' => ['view' => $request->user()?->can('view', $this->resource) ?? false, 'update' => $request->user()?->can('update', $this->resource) ?? false]]; }
}
