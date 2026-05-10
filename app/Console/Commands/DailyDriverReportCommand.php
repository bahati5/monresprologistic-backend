<?php

namespace App\Console\Commands;

use App\Enums\PickupStatus;
use App\Models\Notification;
use App\Models\Pickup;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * §8.5 — Envoie le rapport de tournée de la journée aux managers en fin de journée.
 * Planifié chaque jour à 20h (heure serveur).
 */
class DailyDriverReportCommand extends Command
{
    protected $signature = 'pickups:daily-report
                            {--date= : Date au format Y-m-d (défaut : aujourd\'hui)}
                            {--dry-run : Simuler sans envoyer les notifications}';

    protected $description = 'Génère et envoie le rapport de tournée chauffeur de la journée';

    public function handle(): int
    {
        $date = $this->option('date')
            ? Carbon::parse($this->option('date'))
            : Carbon::today();

        $dryRun = $this->option('dry-run');

        $dayStart = $date->copy()->startOfDay();
        $dayEnd = $date->copy()->endOfDay();

        $pickups = Pickup::query()
            ->where(function ($q) use ($dayStart, $dayEnd) {
                $q->whereBetween('created_at', [$dayStart, $dayEnd])
                  ->orWhereBetween('updated_at', [$dayStart, $dayEnd]);
            })
            ->with(['driver:id,name', 'client:id,name'])
            ->get();

        $completedStatuses = [PickupStatus::Collected, PickupStatus::Delivered, PickupStatus::Completed];
        $pendingStatuses = [PickupStatus::Draft, PickupStatus::DriverAssigned, PickupStatus::Accepted, PickupStatus::EnRoute];

        $stats = [
            'total'      => $pickups->count(),
            'completed'  => $pickups->filter(fn ($p) => in_array($p->status, $completedStatuses, true))->count(),
            'failed'     => $pickups->where('status', PickupStatus::Failed)->count(),
            'pending'    => $pickups->filter(fn ($p) => in_array($p->status, $pendingStatuses, true))->count(),
            'by_driver'  => $pickups->groupBy('driver.name')->map(fn ($group) => [
                'total'     => $group->count(),
                'completed' => $group->filter(fn ($p) => in_array($p->status, $completedStatuses, true))->count(),
                'failed'    => $group->where('status', PickupStatus::Failed)->count(),
            ]),
        ];

        $body = sprintf(
            "📦 Rapport tournée du %s\n\n" .
            "Total : %d ramassages\n" .
            "✅ Complétés : %d\n" .
            "❌ Échecs : %d\n" .
            "⏳ En attente : %d",
            $date->toDateString(),
            $stats['total'],
            $stats['completed'],
            $stats['failed'],
            $stats['pending']
        );

        $this->info($body);

        if (! $dryRun) {
            $managers = User::query()
                ->whereHas('roles', fn ($q) => $q->whereIn('name', ['super_admin', 'agency_admin', 'manager']))
                ->get();

            foreach ($managers as $manager) {
                Notification::query()->create([
                    'user_id'    => $manager->id,
                    'type'       => 'daily_driver_report',
                    'channel'    => 'system',
                    'title'      => "Rapport tournée — {$date->toDateString()}",
                    'body'       => $body,
                    'data'       => $stats,
                    'status'     => 'pending',
                ]);
            }

            Log::info("DailyDriverReport: rapport envoyé à {$managers->count()} manager(s).", $stats);
        }

        return 0;
    }
}
