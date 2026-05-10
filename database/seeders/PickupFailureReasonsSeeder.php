<?php

namespace Database\Seeders;

use App\Models\PickupFailureReason;
use Illuminate\Database\Seeder;

class PickupFailureReasonsSeeder extends Seeder
{
    public function run(): void
    {
        if (PickupFailureReason::query()->exists()) {
            return;
        }

        $defaults = [
            'Client absent',
            'Adresse introuvable',
            'Colis refusé',
            'Créneau non respecté',
            'Accès site impossible',
            'Autre (préciser en commentaire)',
        ];

        $order = 0;
        foreach ($defaults as $label) {
            PickupFailureReason::query()->create([
                'agency_id' => null,
                'label' => $label,
                'sort_order' => $order++,
                'is_active' => true,
            ]);
        }
    }
}
