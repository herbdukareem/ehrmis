<?php

namespace App\Domain\Procurement\Models;

use App\Domain\Organization\Models\Mda;
use App\Models\Concerns\HasMdaScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    use HasMdaScope;

    protected $fillable = [
        'mda_id',
        'procurement_award_id',
        'procurement_solicitation_id',
        'procurement_requisition_id',
        'vendor_id',
        'po_no',
        'title',
        'total_amount',
        'currency',
        'status',
        'order_date',
        'expected_delivery_date',
        'terms',
        'prepared_by',
        'approved_by',
        'approved_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
            'order_date' => 'date',
            'expected_delivery_date' => 'date',
            'approved_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function mda(): BelongsTo { return $this->belongsTo(Mda::class); }
    public function award(): BelongsTo { return $this->belongsTo(ProcurementAward::class, 'procurement_award_id'); }
    public function solicitation(): BelongsTo { return $this->belongsTo(ProcurementSolicitation::class, 'procurement_solicitation_id'); }
    public function requisition(): BelongsTo { return $this->belongsTo(ProcurementRequisition::class, 'procurement_requisition_id'); }
    public function vendor(): BelongsTo { return $this->belongsTo(Vendor::class); }
    public function preparedBy(): BelongsTo { return $this->belongsTo(User::class, 'prepared_by'); }
    public function approvedBy(): BelongsTo { return $this->belongsTo(User::class, 'approved_by'); }
    public function items(): HasMany { return $this->hasMany(PurchaseOrderItem::class); }
    public function receipts(): HasMany { return $this->hasMany(ProcurementReceipt::class); }
}
