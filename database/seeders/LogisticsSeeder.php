<?php

namespace Database\Seeders;

use App\Models\PackagingType;
use App\Models\TransportCompany;
use Illuminate\Database\Seeder;

class LogisticsSeeder extends Seeder
{
    public function run(): void
    {
        $packagingTypes = [
            ['name' => 'Carton standard', 'is_active' => true, 'sort_order' => 1, 'is_billable' => true, 'unit_price' => 5.00],
            ['name' => 'Carton renforcé', 'is_active' => true, 'sort_order' => 2, 'is_billable' => true, 'unit_price' => 10.00],
            ['name' => 'Sac plastique', 'is_active' => true, 'sort_order' => 3, 'is_billable' => false, 'unit_price' => 0.00],
            ['name' => 'Palette', 'is_active' => true, 'sort_order' => 4, 'is_billable' => true, 'unit_price' => 25.00],
            ['name' => 'Emballage client', 'is_active' => true, 'sort_order' => 5, 'is_billable' => false, 'unit_price' => 0.00],
        ];

        foreach ($packagingTypes as $type) {
            PackagingType::firstOrCreate(['name' => $type['name']], $type);
        }

        $transportCompanies = [
            ['name' => 'DHL', 'is_active' => true],
            ['name' => 'FedEx', 'is_active' => true],
            ['name' => 'UPS', 'is_active' => true],
            ['name' => 'Transport local', 'is_active' => true],
            ['name' => 'Livraison propre', 'is_active' => true],
        ];

        foreach ($transportCompanies as $company) {
            TransportCompany::firstOrCreate(['name' => $company['name']], $company);
        }
    }
}
