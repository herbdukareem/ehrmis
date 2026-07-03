<?php

namespace App\Domain\ServiceReporting\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportSubmissionAttachment extends Model
{
    protected $fillable = [
        'report_submission_id',
        'title',
        'file_path',
        'file_mime_type',
        'file_size',
        'uploaded_by',
        'notes',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(ReportSubmission::class, 'report_submission_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
