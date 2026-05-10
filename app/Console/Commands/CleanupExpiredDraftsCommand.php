<?php

namespace App\Console\Commands;

use App\Enums\ShipmentStatus;
use App\Models\Setting;
use App\Models\Shipment;
use Illuminate\Console\Command;

/**
 * @deprecated Remplacé par PruneStaleDraftsCommand (drafts:prune).
 * Conservé temporairement pour nettoyer les anciens Shipments en status Draft
 * qui n'auraient pas encore été migrés via php artisan drafts:migrate-shipments.
 * À supprimer une fois la migration terminée et vérifiée.
 */
class CleanupExpiredDraftsCommand extends Command
{
    protected $signature = 'shipments:cleanup-drafts';

    protected $description = '[DEPRECATED] Supprime les brouillons d\'expédition expirés — remplacé par drafts:prune';

    public function handle(): int
    {
        $this->warn('Cette commande est dépréciée. Utilisez `php artisan drafts:prune` pour le nouveau système de brouillons.');

        $days = (int) Setting::getValue('draft_shipment_expiry_days', 30);
        $cutoff = now()->subDays($days);

        $expired = Shipment::query()
            ->where('status', ShipmentStatus::Draft->value)
            ->where('updated_at', '<=', $cutoff)
            ->get();

        if ($expired->isEmpty()) {
            $this->info('Aucun brouillon d\'expédition legacy à supprimer.');
            return self::SUCCESS;
        }

        foreach ($expired as $shipment) {
            $id = $shipment->id;
            $shipment->delete();
            $this->info("Deleted expired draft shipment #{$id}");
        }

        $this->info("Total deleted: {$expired->count()}");

        return self::SUCCESS;
    }
}
