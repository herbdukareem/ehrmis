<?php

namespace App\Domain\Procurement\Models;

use App\Models\Concerns\HasMdaScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierPayment extends Model
{
    use HasMdaScope;

    protected $fillable = ['mda_id', 'supplier_invoice_id', 'vendor_id', 'request_key', 'payment_no', 'amount', 'currency', 'method', 'status', 'payment_reference', 'paid_date', 'notes', 'prepared_by', 'prepared_at', 'submitted_by', 'submitted_at', 'approved_by', 'approved_at', 'paid_by', 'paid_at', 'rejected_by', 'rejected_at', 'rejection_reason', 'cancelled_by', 'cancelled_at', 'cancellation_reason'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_date' => 'date:Y-m-d', 'prepared_at' => 'datetime', 'submitted_at' => 'datetime',
            'approved_at' => 'datetime', 'paid_at' => 'datetime', 'rejected_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoice::class, 'supplier_invoice_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
