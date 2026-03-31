<?php

namespace Database\Seeders;

use App\Models\Hub;
use App\Models\ServiceType;
use App\Models\Setting;
use App\Models\Status;
use App\Models\StatusTransition;
use Illuminate\Database\Seeder;
class MonresproCoreDataSeeder extends Seeder
{
    public function run(): void
    {
        Setting::query()->updateOrCreate(
            ['key' => 'locker_address_template'],
            ['value' => "Monrespro Logistics — Hub Europe\nCasier : {{locker_code}}\nRue du Hub 1\n1000 Bruxelles, Belgique", 'type' => 'string']
        );

        Setting::setValue('hub_brand_name', 'Monrespro Logistics');

        foreach (
            [
                ['tracking_number_length', '8', 'integer'],
                ['volumetric_divisor', '5000', 'integer'],
                ['default_insurance_pct', '0', 'string'],
                ['default_customs_duty_pct', '0', 'string'],
                ['default_tax_pct', '0', 'string'],
            ] as [$key, $value, $type]
        ) {
            Setting::query()->firstOrCreate(
                ['key' => $key],
                ['value' => $value, 'type' => $type]
            );
        }

        $statusDefs = [
            ['code' => 'created', 'name' => ['fr' => 'Créé', 'en' => 'Created'], 'sort' => 10],
            ['code' => 'at_warehouse', 'name' => ['fr' => 'À l’entrepôt', 'en' => 'At warehouse'], 'sort' => 20],
            ['code' => 'in_transit', 'name' => ['fr' => 'En transit', 'en' => 'In transit'], 'sort' => 30],
            ['code' => 'customs', 'name' => ['fr' => 'Douane', 'en' => 'Customs'], 'sort' => 40],
            ['code' => 'payment_pending', 'name' => ['fr' => 'En attente de paiement', 'en' => 'Payment pending'], 'sort' => 50],
            ['code' => 'financial_review', 'name' => ['fr' => 'Vérification financière', 'en' => 'Financial review'], 'sort' => 55],
            ['code' => 'ready_delivery', 'name' => ['fr' => 'Prêt pour livraison', 'en' => 'Ready for delivery'], 'sort' => 60],
            ['code' => 'delivered', 'name' => ['fr' => 'Livré', 'en' => 'Delivered'], 'sort' => 70],
            ['code' => 'accepted', 'name' => ['fr' => 'Acceptée', 'en' => 'Accepted'], 'sort' => 15],
            ['code' => 'in_preparation', 'name' => ['fr' => 'En préparation', 'en' => 'In preparation'], 'sort' => 25],
            ['code' => 'collected', 'name' => ['fr' => 'Collectée', 'en' => 'Collected'], 'sort' => 32],
            ['code' => 'in_customs', 'name' => ['fr' => 'En douane', 'en' => 'In customs'], 'sort' => 42],
            ['code' => 'arrived', 'name' => ['fr' => 'Arrivée', 'en' => 'Arrived'], 'sort' => 65],
            ['code' => 'out_for_delivery', 'name' => ['fr' => 'En livraison', 'en' => 'Out for delivery'], 'sort' => 68],
            ['code' => 'cancelled', 'name' => ['fr' => 'Annulée', 'en' => 'Cancelled'], 'sort' => 99],
            ['code' => 'pickup_requested', 'name' => ['fr' => 'Demandé', 'en' => 'Requested'], 'sort' => 101],
            ['code' => 'pickup_accepted', 'name' => ['fr' => 'Accepté', 'en' => 'Accepted'], 'sort' => 102],
            ['code' => 'pickup_driver_assigned', 'name' => ['fr' => 'Chauffeur assigné', 'en' => 'Driver assigned'], 'sort' => 103],
            ['code' => 'pickup_en_route', 'name' => ['fr' => 'En route', 'en' => 'En route'], 'sort' => 104],
            ['code' => 'pickup_collected', 'name' => ['fr' => 'Récupéré', 'en' => 'Collected'], 'sort' => 105],
            ['code' => 'pickup_at_hub', 'name' => ['fr' => 'Livré au hub', 'en' => 'Delivered to hub'], 'sort' => 106],
            ['code' => 'cons_created', 'name' => ['fr' => 'Créée', 'en' => 'Created'], 'sort' => 110],
            ['code' => 'cons_items_added', 'name' => ['fr' => 'Colis ajoutés', 'en' => 'Items added'], 'sort' => 111],
            ['code' => 'cons_closed', 'name' => ['fr' => 'Fermée', 'en' => 'Closed'], 'sort' => 112],
            ['code' => 'cons_shipped', 'name' => ['fr' => 'Expédiée', 'en' => 'Shipped'], 'sort' => 113],
            ['code' => 'cons_in_transit', 'name' => ['fr' => 'En transit', 'en' => 'In transit'], 'sort' => 114],
            ['code' => 'cons_arrived', 'name' => ['fr' => 'Arrivée', 'en' => 'Arrived'], 'sort' => 115],
            ['code' => 'cons_distributed', 'name' => ['fr' => 'Distribuée', 'en' => 'Distributed'], 'sort' => 116],
        ];

        $byCode = [];
        foreach ($statusDefs as $def) {
            $byCode[$def['code']] = Status::query()->updateOrCreate(
                ['code' => $def['code']],
                [
                    'name' => $def['name'],
                    'color_hex' => '#64748b',
                    'sort_order' => $def['sort'],
                    'is_active' => true,
                ]
            );
        }

        $chain = ['created', 'accepted', 'in_preparation', 'collected', 'in_transit', 'in_customs', 'at_warehouse', 'arrived', 'out_for_delivery', 'delivered'];
        $chainFiltered = array_values(array_filter($chain, fn ($c) => isset($byCode[$c])));
        for ($i = 1; $i < count($chainFiltered); $i++) {
            StatusTransition::query()->firstOrCreate([
                'from_status_id' => $byCode[$chainFiltered[$i - 1]]->id,
                'to_status_id' => $byCode[$chainFiltered[$i]]->id,
            ]);
        }
        $pickupChain = ['created', 'pickup_accepted', 'pickup_driver_assigned', 'pickup_en_route', 'pickup_collected', 'pickup_at_hub'];
        $pickupFiltered = array_values(array_filter($pickupChain, fn ($c) => isset($byCode[$c])));
        for ($i = 1; $i < count($pickupFiltered); $i++) {
            StatusTransition::query()->firstOrCreate([
                'from_status_id' => $byCode[$pickupFiltered[$i - 1]]->id,
                'to_status_id' => $byCode[$pickupFiltered[$i]]->id,
            ]);
        }
        $consChain = ['cons_created', 'cons_items_added', 'cons_closed', 'cons_shipped', 'cons_in_transit', 'cons_arrived', 'cons_distributed'];
        $consFiltered = array_values(array_filter($consChain, fn ($c) => isset($byCode[$c])));
        for ($i = 1; $i < count($consFiltered); $i++) {
            StatusTransition::query()->firstOrCreate([
                'from_status_id' => $byCode[$consFiltered[$i - 1]]->id,
                'to_status_id' => $byCode[$consFiltered[$i]]->id,
            ]);
        }

        ServiceType::query()->updateOrCreate(
            ['code' => 'air'],
            ['name' => ['fr' => 'Aérien', 'en' => 'Air'], 'is_active' => true]
        );
        ServiceType::query()->updateOrCreate(
            ['code' => 'sea'],
            ['name' => ['fr' => 'Maritime', 'en' => 'Sea'], 'is_active' => true]
        );

        $hubs = [
            ['code' => 'BE-BRU', 'name' => 'Hub Bruxelles', 'lat' => 50.8503, 'lng' => 4.3517],
            ['code' => 'CD-FIH', 'name' => 'Kinshasa', 'lat' => -4.3276, 'lng' => 15.3136],
            ['code' => 'GA-LBV', 'name' => 'Libreville', 'lat' => 0.4162, 'lng' => 9.4673],
        ];
        foreach ($hubs as $idx => $h) {
            Hub::query()->updateOrCreate(
                ['code' => $h['code']],
                [
                    'name' => $h['name'],
                    'latitude' => $h['lat'],
                    'longitude' => $h['lng'],
                    'sort_order' => $idx,
                ]
            );
        }
    }
}
