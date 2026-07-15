<?php

namespace App\Models;

use App\Domain\Organization\Models\Mda;
use App\Domain\Staff\Models\Staff;
use App\Enums\RecordStatus;
use App\Enums\UserType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
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

    public function accessibleMdaIds(): Collection
    {
        $scopeIds = $this->relationLoaded('accessScopes')
            ? $this->accessScopes
                ->whereIn('scope_type', ['mda', 'department'])
                ->pluck('mda_id')
            : $this->accessScopes()
                ->whereIn('scope_type', ['mda', 'department'])
                ->pluck('mda_id');

        return collect([$this->mda_id])
            ->merge($scopeIds)
            ->filter(fn ($mdaId) => $mdaId !== null)
            ->map(fn ($mdaId): int => (int) $mdaId)
            ->unique()
            ->values();
    }

    public function accessibleDepartmentIds(): Collection
    {
        $scopeIds = $this->relationLoaded('accessScopes')
            ? $this->accessScopes
                ->where('scope_type', 'department')
                ->pluck('department_id')
            : $this->accessScopes()
                ->where('scope_type', 'department')
                ->pluck('department_id');

        return collect($scopeIds)
            ->filter(fn ($departmentId) => $departmentId !== null)
            ->map(fn ($departmentId): int => (int) $departmentId)
            ->unique()
            ->values();
    }

    public function primaryAccessibleMdaId(): ?int
    {
        return $this->accessibleMdaIds()->first();
    }

    public function hasAnyMdaAccess(): bool
    {
        return $this->hasGlobalMdaAccess() || $this->accessibleMdaIds()->isNotEmpty();
    }

    public function hasDepartmentRestrictedAccess(): bool
    {
        return ! $this->hasGlobalMdaAccess() && $this->accessibleDepartmentIds()->isNotEmpty();
    }

    public function canAccessMda(?int $mdaId): bool
    {
        if ($mdaId === null) {
            return false;
        }

        return $this->hasGlobalMdaAccess()
            || $this->accessibleMdaIds()->contains((int) $mdaId);
    }

    public function canAccessDepartment(?int $departmentId): bool
    {
        if ($departmentId === null) {
            return false;
        }

        if (! $this->hasDepartmentRestrictedAccess()) {
            return true;
        }

        return $this->accessibleDepartmentIds()->contains((int) $departmentId);
    }

    public function canAccessStaff(Staff $staff): bool
    {
        if (! $this->canAccessMda($staff->mda_id)) {
            return false;
        }

        if (! $this->hasDepartmentRestrictedAccess()) {
            return true;
        }

        $staff->loadMissing('currentEmployment');

        return $this->canAccessDepartment($staff->currentEmployment?->department_id);
    }

    public function scopeToAccessibleMdas(Builder $query, string $column = 'mda_id'): Builder
    {
        if ($this->hasGlobalMdaAccess()) {
            return $query;
        }

        $accessibleMdaIds = $this->accessibleMdaIds();

        if ($accessibleMdaIds->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn($column, $accessibleMdaIds->all());
    }

    public function scopeToAccessibleDepartments(Builder $query, string $column = 'department_id'): Builder
    {
        if ($this->hasGlobalMdaAccess()) {
            return $query;
        }

        if (! $this->hasDepartmentRestrictedAccess()) {
            return $query;
        }

        $accessibleDepartmentIds = $this->accessibleDepartmentIds();

        if ($accessibleDepartmentIds->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn($column, $accessibleDepartmentIds->all());
    }

    public function scopeToAccessibleStaff(Builder $query): Builder
    {
        $this->scopeToAccessibleMdas($query, 'mda_id');

        if (! $this->hasDepartmentRestrictedAccess()) {
            return $query;
        }

        $accessibleDepartmentIds = $this->accessibleDepartmentIds();

        if ($accessibleDepartmentIds->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('currentEmployment', function (Builder $employmentQuery) use ($accessibleDepartmentIds): void {
            $employmentQuery->whereIn('department_id', $accessibleDepartmentIds->all());
        });
    }

    public function scopeVisibleTo(Builder $query, self $user): Builder
    {
        return $user->scopeToAccessibleMdas($query, 'mda_id');
    }
}
