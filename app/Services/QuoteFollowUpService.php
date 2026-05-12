<?php

namespace App\Services;

use App\Enums\AssistedPurchaseStatus;
use App\Models\AssistedPurchase;
use App\Models\QuoteEmailTemplate;
use App\Models\QuoteSnapshot;
use App\Models\Setting;
use Illuminate\Support\Str;

class QuoteFollowUpService
{
    public function sendQuote(
        AssistedPurchase $purchase,
        array $snapshotData,
        array $articlesData,
        array $calculationResult,
        int $staffUserId,
        bool $isUrgent = false,
        ?float $urgencySurchargePercent = null,
        ?string $estimatedDelivery = null,
        ?string $staffMessage = null,
        ?string $revisionReason = null,
    ): QuoteSnapshot {
        $this->bumpQuoteVersionIfResending($purchase);
        $purchase->refresh();

        $validityDays = (int) Setting::getValue('quote_validity_days', '7');
        $version = $purchase->quote_version;

        $expiresAt = now()->addDays($validityDays);
        $token = $this->generateResponseToken();

        $snapshot = QuoteSnapshot::create([
            'assisted_purchase_id' => $purchase->id,
            'version' => $version,
            'snapshot_data' => $snapshotData,
            'articles_data' => $articlesData,
            'total_primary' => $calculationResult['total_primary'],
            'total_secondary' => $calculationResult['total_secondary'],
            'primary_currency' => $calculationResult['primary_currency'],
            'secondary_currency' => $calculationResult['secondary_currency'],
            'exchange_rate_used' => $calculationResult['exchange_rate'],
            'revision_reason' => $revisionReason,
            'created_by' => $staffUserId,
            'is_urgent' => $isUrgent,
            'urgency_surcharge_percent' => $urgencySurchargePercent,
            'estimated_delivery' => $estimatedDelivery,
            'staff_message' => $staffMessage,
            'sent_at' => now(),
            'expires_at' => $expiresAt,
            'response_token' => $token,
            'client_response' => 'pending',
        ]);

        $purchase->update([
            'status' => AssistedPurchaseStatus::QUOTED,
            'quoted_at' => now(),
            'quote_expires_at' => $expiresAt,
            'total_amount' => $calculationResult['total_primary'],
            'is_urgent' => $isUrgent,
            'reminder_count' => 0,
        ]);

        return $snapshot;
    }

    /**
     * Incrémente quote_version avant un nouvel envoi si un snapshot existe déjà (historique des versions).
     *
     * @throws \DomainException Si la limite de 3 versions est atteinte
     */
    private function bumpQuoteVersionIfResending(AssistedPurchase $purchase): void
    {
        $collision = QuoteSnapshot::where('assisted_purchase_id', $purchase->id)
            ->where('version', $purchase->quote_version)
            ->exists();

        if (! $collision) {
            return;
        }

        $next = (int) $purchase->quote_version + 1;
        if ($next > 3) {
            throw new \DomainException('Maximum 3 révisions autorisées pour ce devis.');
        }

        $purchase->update(['quote_version' => $next]);
    }

    public function createRevision(
        AssistedPurchase $purchase,
        string $reason,
    ): int {
        $newVersion = $purchase->quote_version + 1;

        if ($newVersion > 3) {
            throw new \DomainException('Maximum 3 révisions autorisées.');
        }

        $purchase->update(['quote_version' => $newVersion]);

        return $newVersion;
    }

    public function shouldSendReminder(AssistedPurchase $purchase): ?int
    {
        if ($purchase->status !== AssistedPurchaseStatus::QUOTED) {
            return null;
        }

        $autoEnabled = (bool) Setting::getValue('quote_auto_reminders_enabled', '1');
        if (!$autoEnabled) {
            return null;
        }

        if ($purchase->reminder_count >= 2) {
            return null;
        }

        $quotedAt = $purchase->quoted_at;
        if (!$quotedAt) {
            return null;
        }

        $daysSinceQuote = (int) $quotedAt->diffInDays(now());

        if ($purchase->reminder_count === 0) {
            $delay1 = (int) Setting::getValue('quote_reminder_1_delay_days', '2');
            if ($daysSinceQuote >= $delay1) {
                return 1;
            }
        }

        if ($purchase->reminder_count === 1) {
            $delay2 = (int) Setting::getValue('quote_reminder_2_delay_days', '5');
            if ($daysSinceQuote >= $delay2) {
                return 2;
            }
        }

        return null;
    }

    public function markReminderSent(AssistedPurchase $purchase): void
    {
        $purchase->increment('reminder_count');
        $purchase->update(['last_reminder_at' => now()]);
    }

    public function shouldExpire(AssistedPurchase $purchase): bool
    {
        if ($purchase->status !== AssistedPurchaseStatus::QUOTED) {
            return false;
        }

        $expiresAt = $purchase->quote_expires_at;
        if (!$expiresAt) {
            $validityDays = (int) Setting::getValue('quote_validity_days', '7');
            $expiresAt = $purchase->quoted_at?->addDays($validityDays);
        }

        return $expiresAt && $expiresAt->isPast();
    }

    public function expireQuote(AssistedPurchase $purchase): void
    {
        $purchase->update(['status' => AssistedPurchaseStatus::EXPIRED]);

        $latestSnapshot = QuoteSnapshot::where('assisted_purchase_id', $purchase->id)
            ->orderByDesc('version')
            ->first();

        if ($latestSnapshot && $latestSnapshot->client_response === 'pending') {
            $latestSnapshot->update(['client_response' => 'expired']);
        }

        $this->sendExpirationNotification($purchase, $latestSnapshot);
    }

    private function sendExpirationNotification(AssistedPurchase $purchase, ?QuoteSnapshot $snapshot): void
    {
        $user = $purchase->user;
        if (!$user || !$user->email) {
            return;
        }

        \Illuminate\Support\Facades\Mail::to($user->email, $user->name ?? 'Client')
            ->send(new \App\Mail\QuoteExpiredMail($purchase));
    }

    public function sendClarification(
        AssistedPurchase $purchase,
        string $message,
        array $channels,
    ): void {
        $purchase->update([
            'clarification_message' => $message,
            'clarification_sent_at' => now(),
        ]);

        $user = $purchase->user;
        if (!$user) {
            return;
        }

        if (in_array('email', $channels) && $user->email) {
            $subject = config('app.name', 'Monrespro') . ' — Précisions requises pour votre demande #' . $purchase->id;
            \Illuminate\Support\Facades\Mail::raw($message, function ($m) use ($user, $subject) {
                $m->to($user->email)->subject($subject);
            });
        }

        if (in_array('sms', $channels)) {
            $phone = $user->phone ?? ($user->profile?->phone ?? null);
            if ($phone) {
                try {
                    app(\App\Services\Twilio\TwilioGateway::class)->sendSms($phone, $message);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning("SMS clarification failed for AP#{$purchase->id}: {$e->getMessage()}");
                }
            }
        }
    }

    private function generateResponseToken(): string
    {
        return Str::random(64);
    }
}
