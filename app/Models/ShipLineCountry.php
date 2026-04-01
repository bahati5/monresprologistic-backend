<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShipLineCountry extends Model
{
    protected $table = 'ship_line_countries';

    protected $fillable = ['ship_line_id', 'country_id', 'scope'];

    public function shipLine(): BelongsTo
    {
        return $this->belongsTo(ShipLine::class);
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }
}
