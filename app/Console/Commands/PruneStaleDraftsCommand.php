<?php

namespace App\Console\Commands;

use App\Models\FormDraft;
use App\Services\NotificationDispatcher;
use Illuminate\Console\Command;

class PruneStaleDraftsCommand extends Command
{
    protected $signature = 'drafts:prune';

    protected $description = 'Notifie J-3 puis supprime les brouillons de formulaire expirés';

    public function handle(): int
    {
        $this->notifyExpiringSoon();
        $this->deleteExpired();

        return self::SUCCESS;
    }

    private function notifyExpiringSoon(): void
    {
        $drafts = FormDraft::query()
            ->with('user')
            ->expiringSoon(3)
            ->whereDate('expires_at', '=', now()->addDays(3)->toDateString())
            ->get();

        foreach ($drafts as $draft) {
            if (! $draft->user) {
                continue;
            }

            NotificationDispatcher::dispatch(
                user: $draft->user,
                eventKey: 'draft.expiring_soon',
                variables: [
                    'form_type_label' => $draft->form_type->label(),
                    'expires_in_days' => (int) now()->diffInDays($draft->expires_at),
                ],
                actionUrl: $draft->form_type->defaultRoute(),
            );

            $this->info("Notified user #{$draft->user_id} about expiring {$draft->form_type->value} draft #{$draft->id}");
        }

        $this->info("Expiring-soon notifications sent: {$drafts->count()}");
    }

    private function deleteExpired(): void
    {
        $expired = FormDraft::query()->expired()->get();

        foreach ($expired as $draft) {
            $id = $draft->id;
            $draft->delete();
            $this->info("Deleted expired draft #{$id} ({$draft->form_type->value})");
        }

        $this->info("Total expired drafts deleted: {$expired->count()}");
    }
}
