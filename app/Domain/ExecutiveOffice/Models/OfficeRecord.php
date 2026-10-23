<?php

namespace App\Domain\ExecutiveOffice\Models;

use App\Models\Concerns\HasMdaScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OfficeRecord extends Model
{
    use HasMdaScope;

    protected $table = 'executive_office_records';

    protected $guarded = ['id'];

    protected $attributes = ['version' => 1];

    protected function casts(): array
    {
        return ['details' => 'array', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'due_at' => 'datetime', 'archived_at' => 'datetime', 'closed_at' => 'datetime', 'version' => 'integer', 'approved_version' => 'integer'];
    }

    public function notes(): HasMany
    {
        return $this->hasMany(OfficeNote::class, 'record_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(OfficeAttachment::class, 'record_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(OfficeEvent::class, 'record_id');
    }
}
