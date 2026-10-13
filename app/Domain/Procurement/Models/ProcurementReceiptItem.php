<?php

namespace App\Domain\Procurement\Models;

use App\Domain\Budget\Models\BudgetAllocation;
use App\Domain\Budget\Models\BudgetCommitment;
use App\Domain\Organization\Models\Mda;
use App\Models\Concerns\HasMdaScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcurementReceiptItem extends Model
{
    use HasMdaScope;

    protected $fillable = [
        'mda_id',
        'procurement_receipt_id',
        'purchase_order_item_id',
        'budget_allocation_id',
        'budget_commitment_id',
        'item_description',
        'quantity_received',
        'quantity_accepted',
        'quantity_rejected',
        'unit',
        'unit_price',
        'received_amount',
        'accepted_amount',
        'status',
        'remarks',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'quantity_received' => 'decimal:4',
            'quantity_accepted' => 'decimal:4',
            'quantity_rejected' => 'decimal:4',
            'unit_price' => 'decimal:2',
            'received_amount' => 'decimal:2',
            'accepted_amount' => 'decimal:2',
            'metadata' => 'array',
        ];
    }

    public function mda(): BelongsTo { return $this->belongsTo(Mda::class); }
    public function receipt(): BelongsTo { return $this->belongsTo(ProcurementReceipt::class, 'procurement_receipt_id'); }
    public function purchaseOrderItem(): BelongsTo { return $this->belongsTo(PurchaseOrderItem::class); }
    public function budgetAllocation(): BelongsTo { return $this->belongsTo(BudgetAllocation::class); }
    public function budgetCommitment(): BelongsTo { return $this->belongsTo(BudgetCommitment::class); }
}
