<?php

namespace App\Console\Commands;

use App\Jobs\RetrySyncErrorJob;
use App\Models\SyncError;
use Illuminate\Console\Command;

class RetrySyncErrorsCommand extends Command
{
    protected $signature = 'sync:retry-errors {--integration= : Filtrer par intégration}';

    protected $description = 'Re-tente les erreurs de synchronisation dont le next_retry_at est dépassé';

    public function handle(): int
    {
        $query = SyncError::query()
            ->unresolved()
            ->where('next_retry_at', '<=', now())
            ->whereColumn('attempt', '<', 'max_attempts');

        if ($integration = $this->option('integration')) {
            $query->forIntegration($integration);
        }

        $errors = $query->get();

        if ($errors->isEmpty()) {
            $this->info('Aucune erreur de synchronisation à re-tenter.');

            return 0;
        }

        foreach ($errors as $error) {
            RetrySyncErrorJob::dispatch($error);
            $this->line("Dispatched retry for SyncError #{$error->id} ({$error->integration}/{$error->event_type})");
        }

        $this->info("Total : {$errors->count()} retry(s) planifié(s).");

        return 0;
    }
}
