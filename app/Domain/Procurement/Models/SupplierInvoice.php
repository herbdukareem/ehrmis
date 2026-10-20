<?php

namespace App\Domain\Procurement\Models;

use App\Models\Concerns\HasMdaScope;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierInvoice extends Model
{
    use HasMdaScope;

    protected $fillable = ['mda_id', 'purchase_order_id', 'vendor_id', 'invoice_no', 'invoice_date', 'due_date', 'currency', 'total_amount', 'status', 'notes', 'prepared_by', 'prepared_at', 'submitted_by', 'submitted_at', 'approved_by', 'approved_at', 'rejected_by', 'rejected_at', 'rejection_reason', 'cancelled_by', 'cancelled_at', 'cancellation_reason'];

    protected $appends = ['matched_amount', 'paid_amount', 'reserved_payment_amount', 'outstanding_amount', 'available_for_payment', 'payment_status', 'overdue'];

    protected $hidden = ['matched_total', 'paid_total', 'reserved_total'];

    protected function casts(): array
    {
        return ['invoice_date' => 'date:Y-m-d', 'due_date' => 'date:Y-m-d', 'total_amount' => 'decimal:2',
            'prepared_at' => 'datetime', 'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'rejected_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SupplierInvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class);
    }

    public function scopeWithBalances(Builder $query): Builder
    {
        return $query->withSum('items as matched_total', 'total_amount')
            ->withSum(['payments as paid_total' => fn ($q) => $q->where('status', 'paid')], 'amount')
            ->withSum(['payments as reserved_total' => fn ($q) => $q->whereIn('status', ['submitted', 'approved'])], 'amount');
    }

    public function getMatchedAmountAttribute(): string
    {
        return $this->balance('matched_total', fn () => $this->items()->sum('total_amount'));
    }

    public function getPaidAmountAttribute(): string
    {
        return $this->balance('paid_total', fn () => $this->payments()->where('status', 'paid')->sum('amount'));
    }

    public function getReservedPaymentAmountAttribute(): string
    {
        return $this->balance('reserved_total', fn () => $this->payments()->whereIn('status', ['submitted', 'approved'])->sum('amount'));
    }

    public function getOutstandingAmountAttribute(): string
    {
        return in_array($this->status, ['cancelled', 'rejected'], true) ? '0.00' : (string) BigDecimal::of($this->total_amount)->minus($this->paid_amount)->toScale(2);
    }

    public function getAvailableForPaymentAttribute(): string
    {
        return $this->status !== 'approved' ? '0.00' : (string) BigDecimal::of($this->outstanding_amount)->minus($this->reserved_payment_amount)->toScale(2);
    }

    public function getPaymentStatusAttribute(): string
    {
        return BigDecimal::of($this->paid_amount)->isZero() ? 'unpaid' : (BigDecimal::of($this->outstanding_amount)->isZero() ? 'paid' : 'partially_paid');
    }

    public function getOverdueAttribute(): bool
    {
        return $this->status === 'approved' && $this->due_date && $this->due_date->toDateString() < now('Africa/Lagos')->toDateString() && BigDecimal::of($this->outstanding_amount)->isPositive();
    }

    private function balance(string $attribute, callable $query): string
    {
        return (string) BigDecimal::of(array_key_exists($attribute, $this->attributes) ? ($this->attributes[$attribute] ?? 0) : $query())->toScale(2);
    }
}
