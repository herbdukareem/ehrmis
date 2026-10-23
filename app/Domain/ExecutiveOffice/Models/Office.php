<?php

namespace App\Domain\ExecutiveOffice\Models;

use App\Models\Concerns\HasMdaScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Office extends Model
{
    use HasMdaScope;

    protected $table = 'executive_offices';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['pa_confirms_routine' => 'boolean', 'next_reference' => 'integer', 'reminder_minutes' => 'integer', 'workdays' => 'array'];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(OfficeMembership::class, 'office_id');
    }

    public function records(): HasMany
    {
        return $this->hasMany(OfficeRecord::class, 'office_id');
    }

    public function delegations(): HasMany
    {
        return $this->hasMany(OfficeDelegation::class, 'office_id');
    }
}
