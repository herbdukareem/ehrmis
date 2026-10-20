<?php

namespace App\Domain\Procurement\Models;

use App\Domain\Organization\Models\Mda;
use App\Models\Concerns\HasMdaScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Vendor extends Model
{
    use HasMdaScope;

    protected $fillable = [
        'mda_id',
        'vendor_no',
        'name',
        'vendor_type',
        'registration_number',
        'tax_identification_number',
        'contact_person',
        'email',
        'phone',
        'address',
        'status',
        'metadata',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function mda(): BelongsTo { return $this->belongsTo(Mda::class); }
    public function bids(): HasMany { return $this->hasMany(ProcurementBid::class); }
    public function awards(): HasMany { return $this->hasMany(ProcurementAward::class); }
    public function purchaseOrders(): HasMany { return $this->hasMany(PurchaseOrder::class); }
    public function receipts(): HasMany { return $this->hasMany(ProcurementReceipt::class); }
    public function documents(): MorphMany { return $this->morphMany(ProcurementDocument::class, 'documentable'); }
}
