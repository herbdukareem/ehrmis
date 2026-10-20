<?php

namespace App\Domain\Procurement\Models;

use App\Domain\Budget\Models\BudgetAllocation;
use App\Domain\Budget\Models\BudgetCommitment;
use App\Domain\Organization\Models\Mda;
use App\Models\Concerns\HasMdaScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrderItem extends Model
{
    use HasMdaScope;

    protected $fillable = [
        'mda_id',
        'purchase_order_id',
        'procurement_requisition_line_id',
        'budget_allocation_id',
        'budget_commitment_id',
        'item_description',
        'specification',
        'quantity',
        'unit',
        'unit_price',
        'total_amount',
        'status',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'metadata' => 'array',
        ];
    }

    public function mda(): BelongsTo { return $this->belongsTo(Mda::class); }
    public function purchaseOrder(): BelongsTo { return $this->belongsTo(PurchaseOrder::class); }
    public function requisitionLine(): BelongsTo { return $this->belongsTo(ProcurementRequisitionLine::class, 'procurement_requisition_line_id'); }
    public function budgetAllocation(): BelongsTo { return $this->belongsTo(BudgetAllocation::class); }
    public function budgetCommitment(): BelongsTo { return $this->belongsTo(BudgetCommitment::class); }
    public function receiptItems(): HasMany { return $this->hasMany(ProcurementReceiptItem::class); }
}
