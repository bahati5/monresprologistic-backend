<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Profile extends Model
{
    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'phone',
        'phone_secondary',
        'address',
        'landmark',
        'zip_code',
        'city_id',
        'state_id',
        'country_id',
        'agency_id',
        'type',
        'is_active',
        'is_client',
        'is_staff',
        // Driver-specific (nullable, only populated for drivers)
        'license_number',
        'license_expiry',
        'vehicle_type',
        'vehicle_plate',
        'vehicle_brand',
        'coverage_zone',
        'emergency_contact',
        'emergency_phone',
        'is_available',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_client' => 'boolean',
            'is_staff' => 'boolean',
            'is_available' => 'boolean',
            'license_expiry' => 'date',
        ];
    }

    protected $appends = ['full_name'];

    protected function fullName(): Attribute
    {
        return Attribute::get(fn () => trim($this->first_name.' '.$this->last_name));
    }

    // ─── Relationships ───────────────────────────────

    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    /** Contacts enregistrés dans le carnet de ce profil (propriétaire = ce profil). */
    public function addressBookEntries(): HasMany
    {
        return $this->hasMany(AddressBook::class, 'owner_profile_id');
    }

    /**
     * Lignes de carnet où ce profil est le contact enregistré.
     * Pour les profils « propriétaires » qui ont sauvegardé ce contact, voir savedByProfiles().
     */
    public function contactAddressBookEntries(): HasMany
    {
        return $this->hasMany(AddressBook::class, 'contact_profile_id');
    }

    /**
     * Profils propriétaires qui ont ce profil dans leur carnet (côté contact).
     */
    public function savedByProfiles(): BelongsToMany
    {
        return $this->belongsToMany(Profile::class, 'address_books', 'contact_profile_id', 'owner_profile_id')
            ->withPivot('alias', 'is_default', 'notes')
            ->withTimestamps();
    }

    public function sentShipments(): HasMany
    {
        return $this->hasMany(Shipment::class, 'sender_profile_id');
    }

    public function receivedShipments(): HasMany
    {
        return $this->hasMany(Shipment::class, 'recipient_profile_id');
    }

    // ─── Scopes ──────────────────────────────────────

    public function scopeSearch(Builder $query, string $term): Builder
    {
        $like = '%'.$term.'%';

        return $query->where(function (Builder $q) use ($like) {
            $q->where('first_name', 'like', $like)
                ->orWhere('last_name', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->orWhere('phone', 'like', $like)
                ->orWhereRaw("CONCAT(first_name, ' ', last_name) like ?", [$like]);
        });
    }

    public function scopeClients(Builder $query): Builder
    {
        return $query->where('is_client', true);
    }

    public function scopeStaff(Builder $query): Builder
    {
        return $query->where('is_staff', true);
    }

    /** Destinataires / contacts hors fiche client agence et hors équipe (ex. comptoir sans fiche CRM). */
    public function scopePureRecipients(Builder $query): Builder
    {
        return $query->where('is_client', false)->where('is_staff', false);
    }

    public function scopeDrivers(Builder $query): Builder
    {
        return $query->whereNotNull('license_number');
    }
}
