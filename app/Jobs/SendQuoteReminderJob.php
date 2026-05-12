<?php

namespace App\Jobs;

use App\Enums\AssistedPurchaseStatus;
use App\Mail\QuoteReminderMail;
use App\Models\AssistedPurchase;
use App\Services\QuoteFollowUpService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendQuoteReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function handle(QuoteFollowUpService $followUpService): void
    {
        $purchases = AssistedPurchase::where('status', AssistedPurchaseStatus::QUOTED)
            ->where('reminder_count', '<', 2)
            ->whereNotNull('quoted_at')
            ->with('user')
            ->cursor();

        foreach ($purchases as $purchase) {
            try {
                $reminderNumber = $followUpService->shouldSendReminder($purchase);

                if ($reminderNumber === null) {
                    if ($followUpService->shouldExpire($purchase)) {
                        $followUpService->expireQuote($purchase);
                        Log::info("Quote expired: AP#{$purchase->id}");
                    }
                    continue;
                }

                $this->dispatchReminder($purchase, $reminderNumber);
                $followUpService->markReminderSent($purchase);

                Log::info("Quote reminder #{$reminderNumber} sent: AP#{$purchase->id}");
            } catch (\Throwable $e) {
                Log::error("Failed to process quote reminder for AP#{$purchase->id}: {$e->getMessage()}");
            }
        }
    }

    private function dispatchReminder(AssistedPurchase $purchase, int $reminderNumber): void
    {
        $user = $purchase->user;
        if (!$user || !$user->email) {
            return;
        }

        Mail::to($user->email, $user->name ?? 'Client')
            ->send(new QuoteReminderMail($purchase, $reminderNumber));
    }
}
