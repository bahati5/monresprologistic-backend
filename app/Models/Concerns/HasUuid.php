<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

trait HasUuid
{
    public static function bootHasUuid(): void
    {
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Route model binding : accepte l’UUID public ou l’id numérique (liens / SPA historiques).
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        $field ??= $this->getRouteKeyName();

        if ($field === 'uuid') {
            $valueStr = (string) $value;
            $isNumericId = $valueStr !== '' && ctype_digit($valueStr);

            if ($isNumericId) {
                return $this->whereKey((int) $valueStr)->first()
                    ?? $this->where('uuid', $valueStr)->first();
            }

            return $this->where('uuid', $valueStr)->first();
        }

        return parent::resolveRouteBinding($value, $field);
    }

    public function initializeHasUuid(): void
    {
        $this->mergeCasts(['uuid' => 'string']);
    }
}
