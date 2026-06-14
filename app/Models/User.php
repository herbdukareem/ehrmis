<?php

namespace App\Models;

use App\Domain\Organization\Models\Mda;
use App\Enums\RecordStatus;
use App\Enums\UserType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'mda_id',
        'name',
        'email',
        'email_verified_at',
        'password',
        'user_type',
        'status',
        'last_login_at',
        'last_login_ip',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'status' => RecordStatus::class,
            'user_type' => UserType::class,
        ];
    }

    public function mda(): BelongsTo
    {
        return $this->belongsTo(Mda::class);
    }

    public function hasGlobalMdaAccess(): bool
    {
        return in_array(
            $this->user_type?->value,
            [UserType::SUPER_ADMIN->value, UserType::MIS_ADMIN->value],
            true,
        ) || $this->accessScopes()->whereIn('scope_type', ['platform', 'state'])->exists();
    }

    public function accessScopes(): HasMany
    {
        return $this->hasMany(UserAccessScope::class);
    }

    public function hasPlatformAccess(): bool
    {
        return in_array(
            $this->user_type?->value,
            [UserType::SUPER_ADMIN->value, UserType::MIS_ADMIN->value],
            true,
        ) || $this->accessScopes()->where('scope_type', 'platform')->exists();
    }

    public function canAccessMda(int $mdaId): bool
    {
        return $this->hasGlobalMdaAccess()
            || (int) $this->mda_id === $mdaId
            || $this->accessScopes()->where('scope_type', 'mda')->where('mda_id', $mdaId)->exists();
    }

    public function scopeVisibleTo(Builder $query, self $user): Builder
    {
        if ($user->hasGlobalMdaAccess()) {
            return $query;
        }

        if (! $user->mda_id) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('mda_id', $user->mda_id);
    }
}
