<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuoteAuditLog extends Model
{
    use HasUuid;

    protected $table = 'quote_audit_log';

    protected $fillable = [
        'agency_id',
        'entity_type',
        'entity_id',
        'entity_name',
        'action',
        'changes',
        'performed_by',
        'performed_at',
    ];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'performed_at' => 'datetime',
        ];
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public static function record(
        int $agencyId,
        string $entityType,
        int $entityId,
        string $entityName,
        string $action,
        ?array $changes,
        int $performedBy,
    ): self {
        return self::create([
            'agency_id' => $agencyId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'entity_name' => $entityName,
            'action' => $action,
            'changes' => $changes,
            'performed_by' => $performedBy,
            'performed_at' => now(),
        ]);
    }
}
