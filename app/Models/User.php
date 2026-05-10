<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'profile_id',
        'name',
        'email',
        'phone',
        'password',
        'locker_number',
        'agency_id',
        'theme_preference',
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
