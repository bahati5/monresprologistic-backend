<?php

namespace App\Console\Commands;

use App\Jobs\SyncToFreshsalesJob;
use App\Models\User;
use App\Services\Integrations\FreshsalesService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * §15.2 — Relance automatique dans Freshsales pour les clients inactifs depuis 60 jours.
 */
class ReengageInactiveClientsCommand extends Command
{
    protected $signature = 'freshsales:reengage-inactive
                            {--days=60 : Nombre de jours d\'inactivité avant relance}
                            {--dry-run : Simuler sans envoyer}';

    protected $description = 'Créer des tâches de relance dans Freshsales pour les clients inactifs depuis N jours';

    public function handle(FreshsalesService $freshsales): int
    {
        if (! $freshsales->isEnabled()) {
            $this->info('Freshsales non activé — commande ignorée.');

            return 0;
        }

        $days = (int) $this->option('days');
        $dryRun = $this->option('dry-run');
        $threshold = Carbon::now()->subDays($days);

        $inactiveClients = User::query()
            ->whereHas('roles', fn ($q) => $q->where('name', 'client'))
            ->where(function ($q) use ($threshold) {
                // Pas de connexion depuis N jours
                $q->whereNull('last_login_at')
                    ->orWhere('last_login_at', '<', $threshold);
            })
            ->whereDoesntHave('shipments', function ($q) use ($threshold) {
                // Et aucune expédition créée dans les N derniers jours
                $q->where('created_at', '>=', $threshold);
            })
            ->select('id', 'name', 'email', 'phone', 'last_login_at')
            ->get();

        $this->info(sprintf(
            '%d client(s) inactif(s) depuis %d jours identifié(s)%s.',
            $inactiveClients->count(),
            $days,
            $dryRun ? ' (simulation)' : ''
        ));

        $dispatched = 0;
        foreach ($inactiveClients as $client) {
            if (! $dryRun) {
                SyncToFreshsalesJob::dispatch('create_task', [
                    'entity_type'  => 'User',
                    'entity_id'    => $client->id,
                    'contact_id'   => $client->id,
                    'title'        => "Relance client inactif — {$client->name}",
                    'description'  => "Ce client n'a pas eu d'activité depuis plus de {$days} jours. Dernière connexion : "
                        . ($client->last_login_at?->toDateString() ?? 'jamais'),
                    'due_date'     => now()->addDays(2)->toDateString(),
                ]);
            }
            $dispatched++;
        }

        Log::info("ReengageInactiveClients: {$dispatched} tâches dispatché(e)s vers Freshsales.");
        $this->info("{$dispatched} tâche(s) de relance créée(s) dans Freshsales.");

        return 0;
    }
}
