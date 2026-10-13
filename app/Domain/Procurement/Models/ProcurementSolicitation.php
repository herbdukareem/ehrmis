<?php

namespace App\Domain\Procurement\Models;

use App\Domain\Organization\Models\Mda;
use App\Models\Concerns\HasMdaScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class ProcurementSolicitation extends Model
{
    use HasMdaScope;

    protected $fillable = [
        'mda_id',
        'procurement_requisition_id',
        'solicitation_no',
        'title',
        'description',
        'procurement_method',
        'estimated_amount',
        'currency',
        'issue_date',
        'closing_at',
        'status',
        'prepared_by',
        'published_by',
        'published_at',
        'closed_by',
        'closed_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'estimated_amount' => 'decimal:2',
            'issue_date' => 'date',
            'closing_at' => 'datetime',
            'published_at' => 'datetime',
            'closed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function mda(): BelongsTo { return $this->belongsTo(Mda::class); }
    public function requisition(): BelongsTo { return $this->belongsTo(ProcurementRequisition::class, 'procurement_requisition_id'); }
    public function preparedBy(): BelongsTo { return $this->belongsTo(User::class, 'prepared_by'); }
    public function publishedBy(): BelongsTo { return $this->belongsTo(User::class, 'published_by'); }
    public function closedBy(): BelongsTo { return $this->belongsTo(User::class, 'closed_by'); }
    public function bids(): HasMany { return $this->hasMany(ProcurementBid::class); }
    public function evaluations(): HasMany { return $this->hasMany(ProcurementEvaluation::class); }
    public function award(): HasOne { return $this->hasOne(ProcurementAward::class); }
    public function purchaseOrders(): HasMany { return $this->hasMany(PurchaseOrder::class); }
    public function documents(): MorphMany { return $this->morphMany(ProcurementDocument::class, 'documentable'); }
}
