<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuoteLineTemplate extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'agency_id',
        'name',
        'internal_code',
        'description',
        'type',
        'calculation_base',
        'default_value',
        'is_mandatory',
        'is_visible_to_client',
        'is_active',
        'display_order',
        'applies_to',
        'behavior',
    ];

    protected function casts(): array
    {
        return [
            'default_value' => 'decimal:2',
            'is_mandatory' => 'boolean',
            'is_visible_to_client' => 'boolean',
            'is_active' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function quoteTemplateLines(): HasMany
    {
        return $this->hasMany(QuoteTemplateLine::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForAgency($query, int $agencyId)
    {
        return $query->where('agency_id', $agencyId);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('display_order');
    }
}
