<?php

namespace Database\Seeders;

use App\Models\Merchant;
use Illuminate\Database\Seeder;

class MerchantSeeder extends Seeder
{
    public function run(): void
    {
        $merchants = [
            [
                'name' => 'Amazon',
                'domains' => ['amazon.com', 'amazon.fr', 'amazon.de', 'amzn.eu', 'a.co'],
                'logo_url' => 'https://upload.wikimedia.org/wikipedia/commons/a/a9/Amazon_logo.svg',
            ],
            [
                'name' => 'Alibaba',
                'domains' => ['alibaba.com', 'french.alibaba.com', 'spanish.alibaba.com'],
                'logo_url' => 'https://upload.wikimedia.org/wikipedia/en/8/80/Alibaba-Group-Logo.svg',
            ],
            [
                'name' => 'AliExpress',
                'domains' => ['aliexpress.com', 'aliexpress.fr', 's.click.aliexpress.com'],
                'logo_url' => 'https://upload.wikimedia.org/wikipedia/en/5/5b/AliExpress_logo.svg',
            ],
            [
                'name' => 'eBay',
                'domains' => ['ebay.com', 'ebay.fr', 'ebay.co.uk'],
                'logo_url' => 'https://upload.wikimedia.org/wikipedia/commons/1/1b/EBay_logo.svg',
            ],
            [
                'name' => 'Zara',
                'domains' => ['zara.com'],
                'logo_url' => 'https://upload.wikimedia.org/wikipedia/commons/f/fd/Zara_Logo.svg',
            ],
            [
                'name' => 'Shein',
                'domains' => ['shein.com', 'shein.fr', 'm.shein.com'],
                'logo_url' => 'https://upload.wikimedia.org/wikipedia/commons/0/05/Shein_logo.svg',
            ],
            [
                'name' => 'Apple',
                'domains' => ['apple.com', 'apple.fr'],
                'logo_url' => 'https://upload.wikimedia.org/wikipedia/commons/f/fa/Apple_logo_black.svg',
            ],
            [
                'name' => 'StockX',
                'domains' => ['stockx.com'],
                'logo_url' => 'https://upload.wikimedia.org/wikipedia/commons/b/b3/StockX_Logo.svg',
            ],
        ];

        foreach ($merchants as $merchant) {
            Merchant::updateOrCreate(
                ['name' => $merchant['name']],
                [
                    'domains' => $merchant['domains'],
                    'logo_url' => $merchant['logo_url'],
                    'is_active' => true,
                ]
            );
        }
    }
}
