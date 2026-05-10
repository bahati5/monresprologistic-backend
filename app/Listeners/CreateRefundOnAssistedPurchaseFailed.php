<?php

namespace App\Listeners;

use App\Enums\AssistedPurchaseStatus;
use App\Enums\RefundStatus;
use App\Events\AssistedPurchaseStatusChanged;
use App\Models\Refund;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * §5.5 — Quand un achat assisté passe en FAILED (produit indisponible),
 * un remboursement est déclenché automatiquement.
 */
class CreateRefundOnAssistedPurchaseFailed implements ShouldQueue
{
    public function handle(AssistedPurchaseStatusChanged $event): void
    {
        if ($event->newStatus !== AssistedPurchaseStatus::FAILED) {
            return;
        }

        $purchase = $event->purchase;

        if (! $purchase->user_id) {
            return;
        }

        // Ne pas créer de doublon si un remboursement existe déjà pour ce dossier
        $existing = Refund::query()
            ->where('refundable_type', \App\Models\AssistedPurchase::class)
            ->where('refundable_id', $purchase->id)
            ->exists();

        if ($existing) {
            return;
        }

        $amount = (float) ($purchase->total_amount ?? $purchase->quote_amount ?? 0);
        if ($amount <= 0) {
            return;
        }

        $refund = Refund::create([
            'reference_code' => Refund::generateReferenceCode(),
            'refundable_type' => \App\Models\AssistedPurchase::class,
            'refundable_id' => $purchase->id,
            'client_id' => $purchase->user_id,
            'agency_id' => $purchase->agency_id,
            'amount' => $amount,
            'currency' => $purchase->quote_currency ?? 'USD',
            'status' => RefundStatus::Requested,
            'reason' => 'Produit indisponible — remboursement automatique suite à l\'échec de la commande.',
            'reason_category' => 'product_unavailable',
        ]);

        \App\Events\RefundStatusChanged::dispatch($refund, '', RefundStatus::Requested->value, $event->changedBy);
    }
}
