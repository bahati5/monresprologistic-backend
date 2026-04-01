<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
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
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

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
        'phone_mobile',
        'password',
        'locker_number',
        'agency_id',
        'theme_preference',
        'can_view_all_agencies',
        'billing_address',
        'billing_postal_code',
        'billing_country_id',
        'billing_state_id',
        'billing_city_id',
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
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function savedContacts(): BelongsToMany
    {
        return $this->belongsToMany(Profile::class, 'address_books', 'owner_id', 'profile_id')
            ->withPivot('id', 'alias', 'is_default', 'notes')
            ->withTimestamps();
    }

    public function addressBookEntries(): HasMany
    {
        return $this->hasMany(AddressBook::class, 'owner_id');
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function locker(): HasOne
    {
        return $this->hasOne(Locker::class);
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(Recipient::class);
    }

    /**
     * Fiche client CRM liée (un utilisateur portail max. une fiche).
     */
    public function crmClientProfile(): HasOne
    {
        return $this->hasOne(CrmClient::class, 'user_id');
    }

    public function billingCountry(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'billing_country_id');
    }

    public function billingState(): BelongsTo
    {
        return $this->belongsTo(State::class, 'billing_state_id');
    }

    public function billingCity(): BelongsTo
    {
        return $this->belongsTo(City::class, 'billing_city_id');
    }

    public function driverProfile(): HasOne
    {
        return $this->hasOne(DriverProfile::class);
    }

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

