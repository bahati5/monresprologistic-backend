<?php

namespace App\Models;

use App\Enums\SavTicketCategory;
use App\Enums\SavTicketPriority;
use App\Enums\SavTicketStatus;
use App\Models\Concerns\HasUuid;
use App\Support\ConfigurableReferenceCode;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class SavTicket extends Model
{
    use HasUuid;

    protected $fillable = [
        'reference_code',
        'agency_id',
        'client_id',
        'assigned_to',
        'created_by',
        'category',
        'priority',
        'status',
        'channel',
        'subject',
        'description',
        'related_type',
        'related_id',
        'first_response_at',
        'resolved_at',
        'closed_at',
        'escalated_at',
        'sla_deadline_at',
        'zendesk_ticket_id',
        'attachments',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'category' => SavTicketCategory::class,
            'priority' => SavTicketPriority::class,
            'status' => SavTicketStatus::class,
            'attachments' => 'array',
            'meta' => 'array',
            'first_response_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
            'escalated_at' => 'datetime',
            'sla_deadline_at' => 'datetime',
        ];
    }

    protected $appends = ['status_label', 'status_color', 'priority_label', 'priority_color', 'category_label', 'sla_remaining_minutes'];

    public static function generateReferenceCode(): string
    {
        return ConfigurableReferenceCode::allocate(
            'sav_ticket_reference_format',
            'TKT-{year}-{seq}',
            'sav_ticket_reference_prefix',
            'TKT',
            'sav_ticket_reference_seq_pad',
            4,
            'sav_ticket_next_seq',
            static::query(),
        );
    }

    protected function statusLabel(): Attribute
    {
        return Attribute::get(fn () => $this->status instanceof SavTicketStatus ? $this->status->label() : '');
    }

    protected function statusColor(): Attribute
    {
        return Attribute::get(fn () => $this->status instanceof SavTicketStatus ? $this->status->color() : '');
    }

    protected function priorityLabel(): Attribute
    {
        return Attribute::get(fn () => $this->priority instanceof SavTicketPriority ? $this->priority->label() : '');
    }

    protected function priorityColor(): Attribute
    {
        return Attribute::get(fn () => $this->priority instanceof SavTicketPriority ? $this->priority->color() : '');
    }

    protected function categoryLabel(): Attribute
    {
        return Attribute::get(fn () => $this->category instanceof SavTicketCategory ? $this->category->label() : '');
    }

    protected function slaRemainingMinutes(): Attribute
    {
        return Attribute::get(function () {
            if (! $this->sla_deadline_at || $this->status?->slaSuspended()) {
                return null;
            }

            return (int) max(0, now()->diffInMinutes($this->sla_deadline_at, false));
        });
    }

    // ── Relationships ────────────────────────

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SavTicketMessage::class, 'ticket_id')->orderBy('created_at');
    }

    public function related(): MorphTo
    {
        return $this->morphTo();
    }

    // ── Helpers ──────────────────────────────

    public function computeSlaDeadline(): void
    {
        if (! $this->priority instanceof SavTicketPriority) {
            return;
        }

        $minutes = $this->priority->firstResponseMinutes();
        $this->sla_deadline_at = now()->addMinutes($minutes);
    }
}
