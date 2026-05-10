<?php

namespace App\Jobs;

use App\Models\Notification;
use App\Models\SyncError;
use App\Models\User;
use App\Services\Integrations\OdooService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RetrySyncErrorJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public SyncError $syncError) {}

    public function handle(OdooService $odoo): void
    {
        $error = $this->syncError;

        if ($error->resolved) {
            return;
        }

        if (! $odoo->isEnabled()) {
            return;
        }

        try {
            $this->replaySync($odoo, $error);
            $error->markResolved();
            Log::info("SyncError #{$error->id} resolved on retry attempt #{$error->attempt}.");
        } catch (\Throwable $e) {
            $newAttempt = $error->attempt + 1;
            $backoff = [30, 120, 600];

            if ($newAttempt > $error->max_attempts) {
                $error->update([
                    'attempt' => $newAttempt,
                    'error_message' => $e->getMessage(),
                    'next_retry_at' => null,
                ]);
                $this->alertSuperAdmins($error);
                Log::error("SyncError #{$error->id} exhausted all {$error->max_attempts} retries.");
            } else {
                $delay = $backoff[$newAttempt - 1] ?? 600;
                $error->update([
                    'attempt' => $newAttempt,
                    'error_message' => $e->getMessage(),
                    'next_retry_at' => now()->addSeconds($delay),
                ]);
            }
        }
    }

    private function replaySync(OdooService $odoo, SyncError $error): void
    {
        $payload = $error->payload ?? [];

        match ($error->event_type) {
            'shipment_delivered_invoice' => $odoo->createInvoice($payload),
            'refund_credit_note' => $odoo->createCreditNote($payload),
            default => throw new \RuntimeException("Unknown sync event type: {$error->event_type}"),
        };
    }

    private function alertSuperAdmins(SyncError $error): void
    {
        $superAdmins = User::query()
            ->whereHas('roles', fn ($q) => $q->where('name', 'super_admin'))
            ->get();

        foreach ($superAdmins as $admin) {
            Notification::query()->create([
                'user_id' => $admin->id,
                'type' => 'sync_error_max_retries',
                'channel' => 'system',
                'title' => "Échec synchronisation {$error->integration} après {$error->max_attempts} tentatives",
                'body' => "L'erreur #{$error->id} ({$error->event_type}) n'a pas pu être résolue. Intervention manuelle requise.",
                'data' => [
                    'sync_error_id' => $error->id,
                    'integration' => $error->integration,
                    'event_type' => $error->event_type,
                ],
                'action_url' => '/settings?tab=sync-errors',
                'status' => 'pending',
            ]);
        }
    }
}
