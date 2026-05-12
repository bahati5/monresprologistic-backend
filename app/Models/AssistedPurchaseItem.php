<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Support\AssistedPurchaseUrlLabel;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssistedPurchaseItem extends Model
{
    use HasUuid;

    protected $fillable = [
        'assisted_purchase_id',
        'merchant_id',
        'url',
        'name',
        'options',
        'quantity',
        'unit_price',
    ];

    protected $appends = ['display_label'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
        ];
    }

    protected function displayLabel(): Attribute
    {
        return Attribute::get(function () {
            $raw = $this->attributes['name'] ?? null;
            $n = is_string($raw) ? trim($raw) : '';
            if ($n !== '') {
                return $n;
            }

            $u = $this->attributes['url'] ?? '';

            return AssistedPurchaseUrlLabel::fromUrl(is_string($u) ? $u : null);
        });
    }

    public function assistedPurchase(): BelongsTo
    {
        return $this->belongsTo(AssistedPurchase::class);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
