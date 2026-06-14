<?php

namespace App\Models;

use App\Domain\Organization\Models\Mda;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserAccessScope extends Model
{
    protected $fillable = ['user_id', 'scope_type', 'state_code', 'mda_id'];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function mda(): BelongsTo { return $this->belongsTo(Mda::class); }
}
