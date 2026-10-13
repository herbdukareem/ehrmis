<?php

namespace App\Domain\Procurement\Models;

use App\Domain\Organization\Models\Mda;
use App\Models\Concerns\HasMdaScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProcurementReceipt extends Model
{
    use HasMdaScope;

    protected $fillable = [
        'mda_id',
        'purchase_order_id',
        'vendor_id',
        'receipt_no',
        'received_date',
        'delivery_note_no',
        'inspection_status',
        'status',
        'notes',
        'received_by',
        'posted_by',
        'posted_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'received_date' => 'date',
            'posted_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function mda(): BelongsTo { return $this->belongsTo(Mda::class); }
    public function purchaseOrder(): BelongsTo { return $this->belongsTo(PurchaseOrder::class); }
    public function vendor(): BelongsTo { return $this->belongsTo(Vendor::class); }
    public function receivedBy(): BelongsTo { return $this->belongsTo(User::class, 'received_by'); }
    public function postedBy(): BelongsTo { return $this->belongsTo(User::class, 'posted_by'); }
    public function items(): HasMany { return $this->hasMany(ProcurementReceiptItem::class); }
}
