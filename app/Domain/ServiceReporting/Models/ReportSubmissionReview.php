<?php

namespace App\Domain\ServiceReporting\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportSubmissionReview extends Model
{
    protected $fillable = [
        'report_submission_id',
        'action',
        'comment',
        'actor_user_id',
        'acted_at',
        'before_status',
        'after_status',
    ];

    protected function casts(): array
    {
        return ['acted_at' => 'datetime'];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(ReportSubmission::class, 'report_submission_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
