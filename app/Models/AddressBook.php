<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AddressBook extends Model
{
    protected $table = 'address_books';

    protected $fillable = [
        'owner_profile_id',
        'contact_profile_id',
        'alias',
        'is_default',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    public function ownerProfile(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'owner_profile_id');
    }

    public function contactProfile(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'contact_profile_id');
    }
}
