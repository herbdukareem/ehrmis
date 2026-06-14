<?php

namespace App\Domain\Staff\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StaffDocument extends Model
{
    protected $fillable = [
        'staff_id',
        'title',
        'document_type',
        'notes',
        'compiled_pdf_path',
        'compiled_pdf_size',
        'uploaded_by',
    ];

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function pages(): HasMany
    {
        return $this->hasMany(StaffDocumentPage::class)->orderBy('page_number');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
