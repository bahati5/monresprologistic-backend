<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $fillable = ['key', 'value', 'type'];

    protected static function booted(): void
    {
        static::saved(function (Setting $setting): void {
            Cache::forget('setting.'.$setting->key);
        });

        static::deleted(function (Setting $setting): void {
            Cache::forget('setting.'.$setting->key);
        });
    }

    public static function getValue(string $key, ?string $default = null): ?string
    {
        return Cache::rememberForever('setting.'.$key, function () use ($key, $default) {
            $row = static::query()->where('key', $key)->first();

            return $row?->value ?? $default;
        });
    }

    public static function setValue(string $key, ?string $value, string $type = 'string'): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value, 'type' => $type]);
        Cache::forget('setting.'.$key);
    }
}
