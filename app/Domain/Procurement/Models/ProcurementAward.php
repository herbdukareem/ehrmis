<?php

namespace App\Domain\Procurement\Models;

use App\Domain\Organization\Models\Mda;
use App\Models\Concerns\HasMdaScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ProcurementAward extends Model
{
    use HasMdaScope;

    protected $fillable = [
        'mda_id',
        'procurement_solicitation_id',
        'procurement_bid_id',
        'vendor_id',
        'procurement_evaluation_id',
        'award_no',
        'award_amount',
        'currency',
        'status',
        'recommendation_summary',
        'recommended_by',
        'recommended_at',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'award_amount' => 'decimal:2',
            'recommended_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function mda(): BelongsTo { return $this->belongsTo(Mda::class); }
    public function solicitation(): BelongsTo { return $this->belongsTo(ProcurementSolicitation::class, 'procurement_solicitation_id'); }
    public function bid(): BelongsTo { return $this->belongsTo(ProcurementBid::class, 'procurement_bid_id'); }
    public function vendor(): BelongsTo { return $this->belongsTo(Vendor::class); }
    public function evaluation(): BelongsTo { return $this->belongsTo(ProcurementEvaluation::class, 'procurement_evaluation_id'); }
    public function recommendedBy(): BelongsTo { return $this->belongsTo(User::class, 'recommended_by'); }
    public function approvedBy(): BelongsTo { return $this->belongsTo(User::class, 'approved_by'); }
    public function rejectedBy(): BelongsTo { return $this->belongsTo(User::class, 'rejected_by'); }
    public function purchaseOrder(): HasOne { return $this->hasOne(PurchaseOrder::class); }
}
