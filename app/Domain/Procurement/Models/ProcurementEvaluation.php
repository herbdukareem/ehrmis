<?php

namespace App\Domain\Procurement\Models;

use App\Domain\Organization\Models\Mda;
use App\Models\Concerns\HasMdaScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcurementEvaluation extends Model
{
    use HasMdaScope;

    protected $fillable = [
        'mda_id',
        'procurement_solicitation_id',
        'procurement_bid_id',
        'vendor_id',
        'technical_score',
        'financial_score',
        'total_score',
        'status',
        'recommended',
        'rank',
        'recommendation_notes',
        'evaluated_by',
        'evaluated_at',
        'criteria_scores',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'technical_score' => 'decimal:2',
            'financial_score' => 'decimal:2',
            'total_score' => 'decimal:2',
            'recommended' => 'boolean',
            'rank' => 'integer',
            'evaluated_at' => 'datetime',
            'criteria_scores' => 'array',
            'metadata' => 'array',
        ];
    }

    public function mda(): BelongsTo { return $this->belongsTo(Mda::class); }
    public function solicitation(): BelongsTo { return $this->belongsTo(ProcurementSolicitation::class, 'procurement_solicitation_id'); }
    public function bid(): BelongsTo { return $this->belongsTo(ProcurementBid::class, 'procurement_bid_id'); }
    public function vendor(): BelongsTo { return $this->belongsTo(Vendor::class); }
    public function evaluatedBy(): BelongsTo { return $this->belongsTo(User::class, 'evaluated_by'); }
}
