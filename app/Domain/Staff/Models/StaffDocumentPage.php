<?php

namespace App\Domain\Staff\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffDocumentPage extends Model
{
    protected $fillable = [
        'staff_document_id',
        'page_number',
        'file_path',
        'original_name',
        'mime_type',
        'file_size',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(StaffDocument::class, 'staff_document_id');
    }
}
