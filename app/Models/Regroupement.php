<?php

namespace App\Models;

use App\Enums\ShipmentStatus;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Regroupement extends Model
{
    use HasUuid;

    protected $table = 'regroupements';

    protected $fillable = [
        'batch_number',
        'agency_id',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => ShipmentStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Regroupement $model) {
            if (! filled($model->batch_number)) {
                $model->batch_number = static::generateNextBatchNumber();
            }
        });

        static::updated(function (Regroupement $model) {
            if ($model->wasChanged('status')) {
                $value = $model->status instanceof ShipmentStatus
                    ? $model->status->value
                    : (string) $model->status;

                Shipment::query()->where('regroupement_id', $model->id)->update(['status' => $value]);
            }
        });
    }

    /**
     * Format LOT-YYMM-XXX (ex. LOT-2604-001), séquence mensuelle par agence logistique.
     */
    public static function generateNextBatchNumber(): string
    {
        return DB::transaction(function () {
            $ym = now()->format('ym');
            $prefix = "LOT-{$ym}-";

            $last = static::query()
                ->where('batch_number', 'like', $prefix.'%')
                ->lockForUpdate()
                ->orderByDesc('batch_number')
                ->value('batch_number');

            $next = 1;
            if ($last && preg_match('/^'.preg_quote($prefix, '/').'(\d{3})$/', $last, $m)) {
                $next = (int) $m[1] + 1;
            }

            return $prefix.str_pad((string) $next, 3, '0', STR_PAD_LEFT);
        });
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class, 'regroupement_id');
    }
}
