<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Concerns\HasUuid;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, HasUuid, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'profile_id',
        'name',
        'first_name',
        'last_name',
        'email',
        'phone',
        'password',
        'locker_number',
        'agency_id',
        'theme_preference',
        'avatar_path',
        'can_view_all_agencies',
        'notification_preferences',
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
            'password' => 'hashed',
            'can_view_all_agencies' => 'boolean',
            'notification_preferences' => 'array',
        ];
    }

    /**
     * Route model binding: accept public UUID or legacy numeric id (SPA / settings still pass id).
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        $field ??= $this->getRouteKeyName();

        if ($field === 'uuid') {
            $isNumericId = is_numeric($value) || (is_string($value) && ctype_digit($value));
            if ($isNumericId) {
                return $this->whereKey((int) $value)->first()
                    ?? $this->where('uuid', $value)->first();
            }

            return $this->where('uuid', $value)->first();
        }

        return parent::resolveRouteBinding($value, $field);
    }

    // ─── Relationships ───────────────────────────────

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function savedContacts(): BelongsToMany
    {
        return $this->belongsToMany(Profile::class, 'address_books', 'owner_profile_id', 'contact_profile_id', 'profile_id', 'id')
            ->withPivot('id', 'alias', 'is_default', 'notes')
            ->withTimestamps();
    }

    public function addressBookEntries(): HasMany
    {
        return $this->hasMany(AddressBook::class, 'owner_profile_id', 'profile_id');
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function locker(): HasOne
    {
        return $this->hasOne(Locker::class);
    }

    public function createdShipments(): HasMany
    {
        return $this->hasMany(Shipment::class, 'creator_user_id');
    }

    public function savTicketsAssigned(): HasMany
    {
        return $this->hasMany(SavTicket::class, 'assigned_to');
    }

    // ─── RBAC ────────────────────────────────────────

    public function primaryRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function permissionGroups(): Collection
    {
        $role = $this->primaryRole ?? $this->roles()->first();
        if (! $role) {
            return collect();
        }

        return PermissionGroup::whereHas('roles', fn ($q) => $q->where('roles.id', $role->id))
            ->where('is_active', true)
            ->get();
    }

    /**
     * Union of Spatie role/direct permissions + RBAC group-inherited permissions.
     * Works regardless of whether role_id or Spatie model_has_roles is used.
     */
    public function getAllEffectivePermissionCodes(): array
    {
        $spatiePerms = $this->getAllPermissions()->pluck('name')->toArray();

        $role = $this->primaryRole ?? $this->roles()->first();
        if (! $role) {
            return array_values(array_unique($spatiePerms));
        }

        $groupPerms = PermissionGroup::where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->where('roles.id', $role->id))
            ->with('permissions')
            ->get()
            ->flatMap(fn ($g) => $g->permissions->pluck('name'))
            ->toArray();

        return array_values(array_unique(array_merge($spatiePerms, $groupPerms)));
    }

    public function hasEffectivePermission(string $permissionCode): bool
    {
        return in_array($permissionCode, $this->getAllEffectivePermissionCodes());
    }

    public function getAccessibleMenus(): Collection
    {
        $permCodes = $this->getAllEffectivePermissionCodes();

        return Menu::where('is_active', true)
            ->whereHas('activeElements', function ($q) use ($permCodes) {
                $q->where(function ($sub) use ($permCodes) {
                    $sub->whereHas('permissions', fn ($pq) => $pq->whereIn('name', $permCodes));
                })->orWhere(function ($sub) {
                    $sub->whereDoesntHave('permissions');
                });
            })
            ->orderBy('order')
            ->get();
    }

    public function getAccessiblePages(): Collection
    {
        $permCodes = $this->getAllEffectivePermissionCodes();

        return FrontendElement::where('is_active', true)
            ->where('is_page', true)
            ->where(function ($q) use ($permCodes) {
                $q->whereHas('permissions', fn ($pq) => $pq->whereIn('name', $permCodes))
                    ->orWhereDoesntHave('permissions');
            })
            ->with('menu')
            ->orderBy('order')
            ->get();
    }

    // ─── Helpers ─────────────────────────────────────

    public function isSuperAdmin(): bool
    {
        return $this->hasRole('super_admin');
    }

    public function canAccessAllAgencies(): bool
    {
        return $this->isSuperAdmin() && $this->can_view_all_agencies;
    }

    public function hasPermissionName(string $permission): bool
    {
        return $this->can($permission);
    }
}
