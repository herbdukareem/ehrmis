<?php

namespace App\Domain\Procurement\Models;

use App\Domain\Organization\Models\Mda;
use App\Models\Concerns\HasMdaScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class ProcurementBid extends Model
{
    use HasMdaScope;

    protected $fillable = [
        'mda_id',
        'procurement_solicitation_id',
        'vendor_id',
        'bid_no',
        'quoted_amount',
        'currency',
        'submitted_at',
        'valid_until',
        'status',
        'technical_score',
        'financial_score',
        'total_score',
        'notes',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'quoted_amount' => 'decimal:2',
            'submitted_at' => 'datetime',
            'valid_until' => 'date',
            'technical_score' => 'decimal:2',
            'financial_score' => 'decimal:2',
            'total_score' => 'decimal:2',
            'metadata' => 'array',
        ];
    }

    public function mda(): BelongsTo { return $this->belongsTo(Mda::class); }
    public function solicitation(): BelongsTo { return $this->belongsTo(ProcurementSolicitation::class, 'procurement_solicitation_id'); }
    public function vendor(): BelongsTo { return $this->belongsTo(Vendor::class); }
    public function evaluation(): HasOne { return $this->hasOne(ProcurementEvaluation::class); }
    public function award(): HasOne { return $this->hasOne(ProcurementAward::class); }
    public function documents(): MorphMany { return $this->morphMany(ProcurementDocument::class, 'documentable'); }
}
