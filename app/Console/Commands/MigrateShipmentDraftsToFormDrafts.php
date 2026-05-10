<?php

namespace App\Console\Commands;

use App\Enums\FormDraftType;
use App\Enums\ShipmentStatus;
use App\Models\FormDraft;
use App\Models\Setting;
use App\Models\Shipment;
use Illuminate\Console\Command;

class MigrateShipmentDraftsToFormDrafts extends Command
{
    protected $signature = 'drafts:migrate-shipments';

    protected $description = 'Migre les Shipment en status draft vers la table form_drafts puis les supprime';

    public function handle(): int
    {
        $drafts = Shipment::query()
            ->where('status', ShipmentStatus::Draft->value)
            ->with('items')
            ->get();

        if ($drafts->isEmpty()) {
            $this->info('Aucun brouillon d\'expédition à migrer.');

            return self::SUCCESS;
        }

        $migrated = 0;

        foreach ($drafts as $shipment) {
            $payload = [
                'sender_profile_id' => $shipment->sender_profile_id,
                'recipient_profile_id' => $shipment->recipient_profile_id,
                'origin_country_id' => $shipment->origin_country_id,
                'dest_country_id' => $shipment->dest_country_id,
                'shipping_mode_id' => $shipment->service_options['shipping_mode_id'] ?? null,
                'packaging_type_id' => $shipment->service_options['packaging_type_id'] ?? null,
                'transport_company_id' => $shipment->service_options['transport_company_id'] ?? null,
                'ship_line_rate_id' => $shipment->service_options['ship_line_rate_id'] ?? null,
                'declared_currency' => $shipment->declared_currency,
                'notes' => $shipment->service_options['notes'] ?? null,
                'service_options' => $shipment->service_options,
                'items' => $shipment->items->map(fn ($item) => [
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'weight_kg' => $item->weight_kg,
                    'value' => $item->value,
                    'length_cm' => $item->length_cm,
                    'width_cm' => $item->width_cm,
                    'height_cm' => $item->height_cm,
                    'category_id' => $item->category_id ?? null,
                ])->toArray(),
            ];

            $user = $shipment->creator;
            $isClient = $user?->hasRole('client') ?? false;
            $expiryDays = $isClient
                ? (int) Setting::getValue('draft_client_expiry_days', '30')
                : (int) Setting::getValue('draft_staff_expiry_days', '7');

            FormDraft::create([
                'user_id' => $shipment->creator_user_id,
                'form_type' => FormDraftType::Shipment,
                'payload' => $payload,
                'metadata' => ['migrated_from_shipment_id' => $shipment->id],
                'last_saved_at' => $shipment->updated_at,
                'expires_at' => now()->addDays($expiryDays),
                'agency_id' => $shipment->agency_id,
            ]);

            $shipment->items()->delete();
            $shipment->delete();
            $migrated++;
            $this->info("Migrated shipment #{$shipment->id} → form_drafts");
        }

        $this->info("Total migrated: {$migrated}");

        return self::SUCCESS;
    }
}
