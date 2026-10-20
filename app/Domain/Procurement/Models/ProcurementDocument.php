<?php

namespace App\Domain\Procurement\Models;

use App\Domain\Organization\Models\Mda;
use App\Models\Concerns\HasMdaScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ProcurementDocument extends Model
{
    use HasMdaScope;

    protected $fillable = [
        'mda_id',
        'documentable_type',
        'documentable_id',
        'title',
        'document_type',
        'file_path',
        'file_mime_type',
        'file_size',
        'external_url',
        'notes',
        'uploaded_by',
    ];

    public function mda(): BelongsTo { return $this->belongsTo(Mda::class); }
    public function documentable(): MorphTo { return $this->morphTo(); }
    public function uploadedBy(): BelongsTo { return $this->belongsTo(User::class, 'uploaded_by'); }
}
