<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NewsletterSubscriber extends Model
{
    protected $fillable = [
        'email',
        'name',
        'locale',
        'is_active',
        'subscribed_at',
        'unsubscribed_at',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'subscribed_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
        ];
    }

    public static function subscribe(string $email, ?string $name = null, string $source = 'website', string $locale = 'fr'): self
    {
        return static::create([
            'email' => $email,
            'name' => $name,
            'source' => $source,
            'locale' => $locale,
            'is_active' => true,
            'subscribed_at' => now(),
        ]);
    }

    public function unsubscribe(): void
    {
        $this->update([
            'is_active' => false,
            'unsubscribed_at' => now(),
        ]);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByLocale($query, string $locale)
    {
        return $query->where('locale', $locale);
    }
}
