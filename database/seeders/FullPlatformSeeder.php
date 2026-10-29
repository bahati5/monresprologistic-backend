<?php

namespace Database\Seeders;

use App\Enums\AssistedPurchaseStatus;
use App\Models\Agency;
use App\Models\AgencyPaymentCoordinate;
use App\Models\ArticleCategory;
use App\Models\AssistedPurchase;
use App\Models\AssistedPurchaseItem;
use App\Models\AssistedPurchasePayment;
use App\Models\BillingExtra;
use App\Models\ExchangeRate;
use App\Models\Hub;
use App\Models\Locker;
use App\Models\Merchant;
use App\Models\NotificationTemplate;
use App\Models\PackagingType;
use App\Models\PaymentMethod;
use App\Models\PricingRule;
use App\Models\Profile;
use App\Models\QuoteEmailTemplate;
use App\Models\QuoteLineTemplate;
use App\Models\QuoteSnapshot;
use App\Models\QuoteTemplate;
use App\Models\QuoteTemplateLine;
use App\Models\Setting;
use App\Models\ShipLine;
use App\Models\ShipLineCountry;
use App\Models\ShipLineRate;
use App\Models\ShippingMode;
use App\Models\TransportCompany;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Seed complet pour démarrer la plateforme Kotinda (Gabon).
 *
 * Données de démonstration adaptées au marché gabonais :
 * - 5 agences : Libreville (siège), Port-Gentil, et les hubs d'origine Paris, Guangzhou, Dubaï
 * - Hubs d'entreposage (origine et destination)
 * - Modes d'expédition (aérien, maritime, express, national Gabon)
 * - Lignes commerciales avec tarifs en FCFA (XAF)
 * - Méthodes de paiement Gabon (Airtel Money, Moov Money, espèces, virement)
 * - Marchands partenaires enrichis
 * - Catégories d'articles, modèles de devis, templates de notification
 * - Utilisateurs démo pour chaque rôle
 *
 * Taux de référence : 1 EUR = 655,957 XAF (parité fixe), 1 USD ≈ 600 XAF,
 * 1 CNY ≈ 84 XAF, 1 AED ≈ 163 XAF.
 *
 * Usage : php artisan db:seed --class=FullPlatformSeeder
 * (ou via DatabaseSeeder après les seeders de base)
 */
class FullPlatformSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedSettings();
        $this->seedAgencies();
        $this->seedHubs();
        $this->seedShippingModes();
        $this->seedShipLines();
        $this->seedAdditionalMerchants();
        $this->seedAdditionalTransportCompanies();
        $this->seedAdditionalPackagingTypes();
        $this->seedPaymentMethods();
        $this->seedAgencyPaymentCoordinates();
        $this->seedExchangeRates();
        $this->seedArticleCategories();
        $this->seedBillingExtras();
        $this->seedZonesAndPricingRules();
        $this->seedQuoteLineTemplates();
        $this->seedQuoteTemplates();
        $this->seedQuoteEmailTemplates();
        $this->seedNotificationTemplates();
        $this->seedDemoUsers();
        $this->seedDemoAssistedPurchases();

        $this->command?->info('✓ Seed complet Kotinda terminé.');
    }

    /**
     * Identifiants pays / région / ville depuis le référentiel géographique (LocationSeeder).
     *
     * @return array{country_id: ?int, state_id: ?int, city_id: ?int}
     */
    private function locate(string $iso2, ?string $cityName = null): array
    {
        $countryId = \DB::table('countries')->where('iso2', $iso2)->value('id');
        $city = ($countryId && $cityName)
            ? \DB::table('cities')->where('country_id', $countryId)->where('name', $cityName)->first(['id', 'state_id'])
            : null;

        return [
            'country_id' => $countryId,
            'state_id' => $city?->state_id,
            'city_id' => $city?->id,
        ];
    }

    // ─── Paramètres globaux ────────────────────────────────────────────

    private function seedSettings(): void
    {
        $this->command?->info('Paramètres globaux…');

        $settings = [
            // Identité
            ['key' => 'company_name', 'value' => 'Kotinda', 'type' => 'string'],
            ['key' => 'company_legal_name', 'value' => 'Kotinda SARL', 'type' => 'string'],
            ['key' => 'company_tagline', 'value' => 'Achat en ligne international et envoi de colis vers le Gabon et dans le monde', 'type' => 'string'],
            ['key' => 'company_email', 'value' => 'info@kotinda.ga', 'type' => 'string'],
            ['key' => 'company_phone', 'value' => '+241 011 44 20 00', 'type' => 'string'],
            ['key' => 'company_phone_fr', 'value' => '+33 1 84 80 12 34', 'type' => 'string'],
            ['key' => 'company_phone_whatsapp', 'value' => '+241 077 44 20 00', 'type' => 'string'],
            ['key' => 'company_website', 'value' => 'https://www.kotinda.ga', 'type' => 'string'],
            ['key' => 'company_website_logistics', 'value' => 'https://app.kotinda.ga', 'type' => 'string'],
            ['key' => 'company_founded_year', 'value' => '2026', 'type' => 'string'],

            // Documents / identité visuelle
            ['key' => 'site_name', 'value' => 'Kotinda', 'type' => 'string'],
            ['key' => 'site_email', 'value' => 'info@kotinda.ga', 'type' => 'string'],
            ['key' => 'phone_fixed', 'value' => '+241 011 44 20 00', 'type' => 'string'],
            ['key' => 'phone_mobile', 'value' => '+241 077 44 20 00', 'type' => 'string'],
            ['key' => 'address', 'value' => 'Boulevard Triomphal Omar Bongo, quartier Louis', 'type' => 'string'],
            ['key' => 'city', 'value' => 'Libreville', 'type' => 'string'],
            ['key' => 'country', 'value' => 'Gabon', 'type' => 'string'],

            // Devise (franc CFA d'Afrique centrale)
            ['key' => 'currency', 'value' => 'XAF', 'type' => 'string'],
            ['key' => 'currency_symbol', 'value' => 'FCFA', 'type' => 'string'],
            ['key' => 'symbol_position', 'value' => 'suffix', 'type' => 'string'],
            ['key' => 'decimals', 'value' => '0', 'type' => 'integer'],
            ['key' => 'quote_primary_currency', 'value' => 'XAF', 'type' => 'string'],
            ['key' => 'quote_secondary_currency_enabled', 'value' => '1', 'type' => 'boolean'],
            ['key' => 'quote_secondary_currency', 'value' => 'EUR', 'type' => 'string'],
            ['key' => 'quote_secondary_currency_rate', 'value' => '0.001524', 'type' => 'string'],

            // Préfixes (casiers et suivi)
            ['key' => 'locker_prefix', 'value' => 'KTD', 'type' => 'string'],
            ['key' => 'tracking_prefix', 'value' => 'KTD', 'type' => 'string'],
            ['key' => 'locker_address_template', 'value' => "Kotinda Hub Paris\n{{locker_code}}\n95700 Roissy-en-France, France", 'type' => 'string'],

            // Devis
            ['key' => 'quote_validity_days', 'value' => '7', 'type' => 'integer'],
            ['key' => 'quote_reminder_1_delay_days', 'value' => '2', 'type' => 'integer'],
            ['key' => 'quote_reminder_2_delay_days', 'value' => '5', 'type' => 'integer'],
            ['key' => 'quote_auto_reminders_enabled', 'value' => '1', 'type' => 'boolean'],

            // Commission assistance achat (7 %, minimum 6 500 FCFA ≈ 10 EUR)
            ['key' => 'assisted_purchase_commission_rate', 'value' => '7', 'type' => 'string'],
            ['key' => 'assisted_purchase_commission_min', 'value' => '6500', 'type' => 'string'],
            ['key' => 'assisted_purchase_commission_currency', 'value' => 'XAF', 'type' => 'string'],

            // Numérotation devis / achats assistés
            ['key' => 'quote_reference_format', 'value' => '{prefix}-{YYYY}-{seq}', 'type' => 'string'],
            ['key' => 'quote_reference_prefix', 'value' => 'DEV', 'type' => 'string'],
            ['key' => 'quote_reference_seq_pad', 'value' => '5', 'type' => 'integer'],
            ['key' => 'quote_next_seq', 'value' => '1', 'type' => 'integer'],

            // Stockage gratuit dans les hubs d'origine
            ['key' => 'free_storage_days', 'value' => '45', 'type' => 'integer'],

            // Poids volumétrique
            ['key' => 'volumetric_divisor_air', 'value' => '5000', 'type' => 'integer'],
            ['key' => 'volumetric_divisor_sea', 'value' => '1000', 'type' => 'integer'],

            // Tracking
            ['key' => 'tracking_url_prefix', 'value' => 'https://app.kotinda.ga/tracking?code=', 'type' => 'string'],

            // Site / portail client
            ['key' => 'site_url', 'value' => 'https://www.kotinda.ga', 'type' => 'string'],
            ['key' => 'client_portal_url', 'value' => 'https://app.kotinda.ga', 'type' => 'string'],

            // Réseaux sociaux
            ['key' => 'social_facebook', 'value' => 'https://www.facebook.com/kotindagabon', 'type' => 'string'],
            ['key' => 'social_instagram', 'value' => 'https://www.instagram.com/kotindagabon', 'type' => 'string'],
            ['key' => 'social_twitter', 'value' => 'https://twitter.com/kotindagabon', 'type' => 'string'],
            ['key' => 'social_youtube', 'value' => 'https://www.youtube.com/@kotindagabon', 'type' => 'string'],
            ['key' => 'social_linkedin', 'value' => 'https://www.linkedin.com/company/kotinda', 'type' => 'string'],

            // Délais de livraison indicatifs
            ['key' => 'delivery_estimate_national_same_province', 'value' => '1-2 jours ouvrés (Grand Libreville, Port-Gentil)', 'type' => 'string'],
            ['key' => 'delivery_estimate_national_inter_province', 'value' => '3-7 jours ouvrés (Franceville, Oyem, Lambaréné…)', 'type' => 'string'],
            ['key' => 'delivery_estimate_international_air', 'value' => '5-10 jours ouvrés', 'type' => 'string'],
            ['key' => 'delivery_estimate_international_sea', 'value' => '5-8 semaines', 'type' => 'string'],
        ];

        foreach ($settings as $s) {
            Setting::firstOrCreate(['key' => $s['key']], $s);
        }
    }

    // ─── Agences ───────────────────────────────────────────────────────

    private function seedAgencies(): void
    {
        $this->command?->info('Agences…');

        $agencies = [
            [
                'code' => 'LBV',
                'name' => 'Kotinda Libreville (Siège)',
                'slug' => 'libreville',
                'default_currency' => 'XAF',
                'exchange_rates' => ['EUR_XAF' => 655.957, 'USD_XAF' => 600, 'CNY_XAF' => 84, 'AED_XAF' => 163],
                'is_active' => true,
                'contact_phone' => '+241 011 44 20 00',
                'contact_phone_secondary' => '+241 077 44 20 00',
                'contact_email' => 'libreville@kotinda.ga',
                'address' => 'Boulevard Triomphal Omar Bongo, quartier Louis, Libreville, Gabon',
                'location' => ['GA', 'Libreville'],
            ],
            [
                'code' => 'POG',
                'name' => 'Kotinda Port-Gentil',
                'slug' => 'port-gentil',
                'default_currency' => 'XAF',
                'exchange_rates' => ['EUR_XAF' => 655.957, 'USD_XAF' => 600, 'CNY_XAF' => 84, 'AED_XAF' => 163],
                'is_active' => true,
                'contact_phone' => '+241 011 55 30 00',
                'contact_phone_secondary' => '+241 066 55 30 00',
                'contact_email' => 'portgentil@kotinda.ga',
                'address' => 'Avenue Savorgnan de Brazza, quartier Grand Village, Port-Gentil, Gabon',
                'location' => ['GA', 'Port-Gentil'],
            ],
            [
                'code' => 'PAR',
                'name' => 'Kotinda Paris (hub d\'origine)',
                'slug' => 'paris',
                'default_currency' => 'EUR',
                'exchange_rates' => ['EUR_XAF' => 655.957],
                'is_active' => true,
                'contact_phone' => '+33 1 84 80 12 34',
                'contact_email' => 'paris@kotinda.ga',
                'address' => 'Zone de fret Roissy-CDG, 95700 Roissy-en-France, France',
                'location' => ['FR', 'Paris'],
            ],
            [
                'code' => 'GZH',
                'name' => 'Kotinda Guangzhou (hub d\'origine)',
                'slug' => 'guangzhou',
                'default_currency' => 'CNY',
                'exchange_rates' => ['CNY_XAF' => 84],
                'is_active' => true,
                'contact_phone' => '+86 20 8888 1234',
                'contact_email' => 'guangzhou@kotinda.ga',
                'address' => 'District de Baiyun, Guangzhou, Chine',
                'location' => ['CN', 'Guangzhou'],
            ],
            [
                'code' => 'DXB',
                'name' => 'Kotinda Dubaï (hub d\'origine)',
                'slug' => 'dubai',
                'default_currency' => 'AED',
                'exchange_rates' => ['AED_XAF' => 163],
                'is_active' => true,
                'contact_phone' => '+971 4 255 1234',
                'contact_email' => 'dubai@kotinda.ga',
                'address' => 'Deira, près de Dubai Creek, Dubaï, Émirats arabes unis',
                'location' => ['AE', 'Dubai'],
            ],
        ];

        foreach ($agencies as $data) {
            [$iso2, $city] = $data['location'];
            unset($data['location']);
            Agency::updateOrCreate(['code' => $data['code']], array_merge($data, $this->locate($iso2, $city)));
        }
    }

    // ─── Hubs / Entrepôts ──────────────────────────────────────────────

    private function seedHubs(): void
    {
        $this->command?->info('Hubs / entrepôts…');

        $hubs = [
            ['code' => 'HUB-PAR', 'name' => 'Entrepôt Paris – Roissy-CDG (France)', 'latitude' => '49.0097', 'longitude' => '2.5479', 'sort_order' => 1],
            ['code' => 'HUB-GZ', 'name' => 'Entrepôt Guangzhou (Chine)', 'latitude' => '23.1291', 'longitude' => '113.2644', 'sort_order' => 2],
            ['code' => 'HUB-DXB', 'name' => 'Entrepôt Dubaï – Deira (Émirats arabes unis)', 'latitude' => '25.2711', 'longitude' => '55.3075', 'sort_order' => 3],
            ['code' => 'HUB-MIA', 'name' => 'Entrepôt Miami (USA)', 'latitude' => '25.7617', 'longitude' => '-80.1918', 'sort_order' => 4],
            ['code' => 'HUB-LBV', 'name' => 'Entrepôt Libreville – Owendo (Gabon)', 'latitude' => '0.2917', 'longitude' => '9.5044', 'sort_order' => 5],
            ['code' => 'HUB-POG', 'name' => 'Entrepôt Port-Gentil (Gabon)', 'latitude' => '-0.7193', 'longitude' => '8.7815', 'sort_order' => 6],
        ];

        foreach ($hubs as $hub) {
            Hub::updateOrCreate(['code' => $hub['code']], $hub);
        }
    }

    // ─── Modes d'expédition ────────────────────────────────────────────

    private function seedShippingModes(): void
    {
        $this->command?->info('Modes d\'expédition…');

        $modes = [
            [
                'name' => 'Fret aérien',
                'description' => 'Transport par avion. Délai : 5–10 jours ouvrés. Idéal pour colis urgents ou de petit volume.',
                'is_active' => true,
                'sort_order' => 1,
                'volumetric_divisor' => 5000,
                'default_pricing_type' => 'per_kg',
                'delivery_options' => ['standard', 'express', 'priority'],
            ],
            [
                'name' => 'Fret maritime',
                'description' => 'Transport par bateau jusqu\'au port d\'Owendo. Délai : 5–8 semaines. Économique pour gros volumes et marchandises lourdes.',
                'is_active' => true,
                'sort_order' => 2,
                'volumetric_divisor' => 1000,
                'default_pricing_type' => 'per_volume',
                'delivery_options' => ['FCL', 'LCL', 'groupage'],
            ],
            [
                'name' => 'Courrier express',
                'description' => 'Livraison express via DHL, FedEx ou Aramex. Délai : 3–5 jours. Suivi en temps réel.',
                'is_active' => true,
                'sort_order' => 3,
                'volumetric_divisor' => 5000,
                'default_pricing_type' => 'per_kg',
                'delivery_options' => ['DHL Express', 'FedEx Priority', 'Aramex'],
            ],
            [
                'name' => 'Envoi national Gabon',
                'description' => 'Livraison intérieure au Gabon. Grand Libreville et Port-Gentil : 1–2 jours, autres provinces (Franceville, Oyem, Lambaréné…) : 3–7 jours.',
                'is_active' => true,
                'sort_order' => 4,
                'volumetric_divisor' => 5000,
                'default_pricing_type' => 'per_kg',
                'delivery_options' => ['standard', 'express'],
            ],
        ];

        foreach ($modes as $mode) {
            ShippingMode::updateOrCreate(['name' => $mode['name']], $mode);
        }
    }

    // ─── Lignes commerciales + tarifs (FCFA) ─────────────────────────

    private function seedShipLines(): void
    {
        $this->command?->info('Lignes commerciales et tarifs…');

        $aerien = ShippingMode::where('name', 'Fret aérien')->first();
        $maritime = ShippingMode::where('name', 'Fret maritime')->first();

        if (! $aerien || ! $maritime) {
            $this->command?->warn('Modes d\'expédition non trouvés, lignes ignorées.');
            return;
        }

        // Contrainte unique (ship_line_id, shipping_mode_id) → une seule rate par ligne+mode.
        // Les paliers de poids sont modélisés comme des lignes distinctes. Tarifs en XAF (FCFA) par kg.
        $lines = [
            // ── France → Gabon ──
            [
                'name' => 'France → Gabon (aérien 1–10 kg)',
                'description' => 'Fret aérien Paris-CDG – Libreville, colis de 1 à 10 kg.',
                'rates' => [['mode' => $aerien, 'price' => 8900, 'label' => '1–10 kg']],
                'countries' => ['FR', 'GA'],
            ],
            [
                'name' => 'France → Gabon (aérien +10 kg)',
                'description' => 'Fret aérien Paris-CDG – Libreville, colis de plus de 10 kg.',
                'rates' => [['mode' => $aerien, 'price' => 7500, 'label' => '+10 kg']],
                'countries' => ['FR', 'GA'],
            ],
            [
                'name' => 'France → Gabon (maritime)',
                'description' => 'Fret maritime Le Havre – Owendo (groupage).',
                'rates' => [['mode' => $maritime, 'price' => 3500, 'label' => 'Groupage, par kg']],
                'countries' => ['FR', 'GA'],
            ],
            // ── Chine → Gabon ──
            [
                'name' => 'Chine → Gabon (aérien)',
                'description' => 'Fret aérien Guangzhou – Libreville.',
                'rates' => [['mode' => $aerien, 'price' => 9500, 'label' => 'Par kilo']],
                'countries' => ['CN', 'GA'],
            ],
            [
                'name' => 'Chine → Gabon (maritime)',
                'description' => 'Fret maritime Guangzhou (Nansha) – Owendo (groupage).',
                'rates' => [['mode' => $maritime, 'price' => 2500, 'label' => 'Groupage, par kg']],
                'countries' => ['CN', 'GA'],
            ],
            // ── Émirats arabes unis → Gabon ──
            [
                'name' => 'Dubaï → Gabon (aérien 1–10 kg)',
                'description' => 'Fret aérien Dubaï – Libreville, colis de 1 à 10 kg.',
                'rates' => [['mode' => $aerien, 'price' => 7900, 'label' => '1–10 kg']],
                'countries' => ['AE', 'GA'],
            ],
            [
                'name' => 'Dubaï → Gabon (aérien +10 kg)',
                'description' => 'Fret aérien Dubaï – Libreville, colis de plus de 10 kg.',
                'rates' => [['mode' => $aerien, 'price' => 6500, 'label' => '+10 kg']],
                'countries' => ['AE', 'GA'],
            ],
            [
                'name' => 'Dubaï → Gabon (maritime)',
                'description' => 'Fret maritime Jebel Ali – Owendo (groupage).',
                'rates' => [['mode' => $maritime, 'price' => 3000, 'label' => 'Groupage, par kg']],
                'countries' => ['AE', 'GA'],
            ],
            // ── USA → Gabon ──
            [
                'name' => 'USA → Gabon (aérien 1–10 kg)',
                'description' => 'Fret aérien Miami – Libreville, colis de 1 à 10 kg.',
                'rates' => [['mode' => $aerien, 'price' => 13500, 'label' => '1–10 kg']],
                'countries' => ['US', 'GA'],
            ],
            [
                'name' => 'USA → Gabon (aérien +10 kg)',
                'description' => 'Fret aérien Miami – Libreville, colis de plus de 10 kg.',
                'rates' => [['mode' => $aerien, 'price' => 11500, 'label' => '+10 kg']],
                'countries' => ['US', 'GA'],
            ],
            // ── Gabon → France ──
            [
                'name' => 'Gabon → France (aérien 1–10 kg)',
                'description' => 'Export Libreville – Paris, colis de 1 à 10 kg.',
                'rates' => [['mode' => $aerien, 'price' => 9500, 'label' => '1–10 kg']],
                'countries' => ['GA', 'FR'],
            ],
            [
                'name' => 'Gabon → France (aérien +10 kg)',
                'description' => 'Export Libreville – Paris, colis de plus de 10 kg.',
                'rates' => [['mode' => $aerien, 'price' => 8000, 'label' => '+10 kg']],
                'countries' => ['GA', 'FR'],
            ],
        ];

        foreach ($lines as $lineData) {
            $line = ShipLine::updateOrCreate(
                ['name' => $lineData['name']],
                ['description' => $lineData['description'], 'is_active' => true]
            );

            foreach ($lineData['rates'] as $rateData) {
                ShipLineRate::updateOrCreate(
                    ['ship_line_id' => $line->id, 'shipping_mode_id' => $rateData['mode']->id],
                    ['unit_price' => $rateData['price'], 'currency' => 'XAF', 'is_active' => true, 'delivery_label_override' => $rateData['label']]
                );
            }

            $scopes = ['origin', 'destination'];
            foreach ($lineData['countries'] as $idx => $iso2) {
                $countryId = \DB::table('countries')->where('iso2', $iso2)->value('id');
                if ($countryId) {
                    ShipLineCountry::updateOrCreate(
                        ['ship_line_id' => $line->id, 'country_id' => $countryId, 'scope' => $scopes[$idx] ?? 'origin'],
                        []
                    );
                }
            }
        }
    }

    // ─── Marchands supplémentaires ─────────────────────────────────────

    private function seedAdditionalMerchants(): void
    {
        $this->command?->info('Marchands supplémentaires…');

        $merchants = [
            ['name' => 'Temu', 'domains' => ['temu.com'], 'logo_url' => 'https://upload.wikimedia.org/wikipedia/commons/4/4b/Temu_logo.svg'],
            ['name' => 'Walmart', 'domains' => ['walmart.com'], 'logo_url' => 'https://upload.wikimedia.org/wikipedia/commons/c/ca/Walmart_logo.svg'],
            ['name' => 'Target', 'domains' => ['target.com'], 'logo_url' => 'https://upload.wikimedia.org/wikipedia/commons/9/9a/Target_logo.svg'],
            ['name' => 'Best Buy', 'domains' => ['bestbuy.com', 'bestbuy.ca'], 'logo_url' => 'https://upload.wikimedia.org/wikipedia/commons/f/f5/Best_Buy_Logo.svg'],
            ['name' => "Macy's", 'domains' => ['macys.com'], 'logo_url' => 'https://upload.wikimedia.org/wikipedia/commons/4/4a/Macys_logo.svg'],
            ['name' => 'Sephora', 'domains' => ['sephora.com', 'sephora.fr'], 'logo_url' => 'https://upload.wikimedia.org/wikipedia/commons/6/6e/Sephora_logo.svg'],
            ['name' => 'Nordstrom', 'domains' => ['nordstrom.com'], 'logo_url' => 'https://upload.wikimedia.org/wikipedia/commons/1/14/Nordstrom_Logo.svg'],
            ['name' => 'Nike', 'domains' => ['nike.com', 'nike.fr'], 'logo_url' => 'https://upload.wikimedia.org/wikipedia/commons/a/a6/Logo_NIKE.svg'],
            ['name' => 'Adidas', 'domains' => ['adidas.com', 'adidas.fr'], 'logo_url' => 'https://upload.wikimedia.org/wikipedia/commons/2/20/Adidas_Logo.svg'],
            ['name' => 'H&M', 'domains' => ['hm.com', 'www2.hm.com'], 'logo_url' => 'https://upload.wikimedia.org/wikipedia/commons/5/53/H%26M-Logo.svg'],
            ['name' => 'ASOS', 'domains' => ['asos.com', 'asos.fr'], 'logo_url' => 'https://upload.wikimedia.org/wikipedia/commons/d/d5/ASOS_logo.svg'],
            ['name' => 'Samsung', 'domains' => ['samsung.com', 'samsung.fr'], 'logo_url' => 'https://upload.wikimedia.org/wikipedia/commons/2/24/Samsung_Logo.svg'],
            ['name' => 'Decathlon', 'domains' => ['decathlon.com', 'decathlon.fr', 'decathlon.be'], 'logo_url' => 'https://upload.wikimedia.org/wikipedia/commons/0/08/Decathlon_Logo.svg'],
            ['name' => 'Cdiscount', 'domains' => ['cdiscount.com'], 'logo_url' => 'https://upload.wikimedia.org/wikipedia/fr/2/2e/Cdiscount_logo.svg'],
            ['name' => 'Fnac', 'domains' => ['fnac.com', 'fnac.be'], 'logo_url' => 'https://upload.wikimedia.org/wikipedia/commons/2/2e/Fnac_Logo.svg'],
            ['name' => 'Zalando', 'domains' => ['zalando.com', 'zalando.fr', 'zalando.be'], 'logo_url' => 'https://upload.wikimedia.org/wikipedia/commons/0/0b/Zalando_logo.svg'],
            ['name' => 'Wish', 'domains' => ['wish.com'], 'logo_url' => 'https://upload.wikimedia.org/wikipedia/commons/6/6f/Wish_logo.svg'],
            ['name' => 'Jumia', 'domains' => ['jumia.com', 'jumia.ci', 'jumia.com.ng'], 'logo_url' => 'https://upload.wikimedia.org/wikipedia/commons/0/01/Jumia_logo.svg'],
            ['name' => 'DHgate', 'domains' => ['dhgate.com'], 'logo_url' => null],
            ['name' => 'Banggood', 'domains' => ['banggood.com'], 'logo_url' => null],
            ['name' => 'iHerb', 'domains' => ['iherb.com'], 'logo_url' => null],
            ['name' => 'IKEA', 'domains' => ['ikea.com', 'ikea.fr', 'ikea.be'], 'logo_url' => 'https://upload.wikimedia.org/wikipedia/commons/c/c5/Ikea_logo.svg'],
        ];

        foreach ($merchants as $m) {
            Merchant::updateOrCreate(
                ['name' => $m['name']],
                ['domains' => $m['domains'], 'logo_url' => $m['logo_url'], 'is_active' => true]
            );
        }
    }

    // ─── Transporteurs supplémentaires ─────────────────────────────────

    private function seedAdditionalTransportCompanies(): void
    {
        $this->command?->info('Transporteurs supplémentaires…');

        $companies = [
            ['name' => 'La Poste France', 'is_active' => true],
            ['name' => 'Gabon Poste', 'is_active' => true],
            ['name' => 'Emirates SkyCargo', 'is_active' => true],
            ['name' => 'Aramex', 'is_active' => true],
            ['name' => 'USPS', 'is_active' => true],
            ['name' => 'TNT', 'is_active' => true],
            ['name' => 'Chronopost', 'is_active' => true],
            ['name' => 'Colissimo', 'is_active' => true],
            ['name' => 'GLS', 'is_active' => true],
            ['name' => 'DPD', 'is_active' => true],
            ['name' => 'PostNL', 'is_active' => true],
            ['name' => 'Royal Mail', 'is_active' => true],
            ['name' => 'China Post', 'is_active' => true],
            ['name' => 'SF Express', 'is_active' => true],
            ['name' => 'Yanwen', 'is_active' => true],
        ];

        foreach ($companies as $c) {
            TransportCompany::firstOrCreate(['name' => $c['name']], $c);
        }
    }

    // ─── Types d'emballage supplémentaires ─────────────────────────────

    private function seedAdditionalPackagingTypes(): void
    {
        $additional = [
            ['name' => 'Enveloppe matelassée', 'is_active' => true, 'sort_order' => 6, 'is_billable' => true, 'unit_price' => 1300.00],
            ['name' => 'Caisse en bois', 'is_active' => true, 'sort_order' => 7, 'is_billable' => true, 'unit_price' => 23000.00],
            ['name' => 'Film étirable', 'is_active' => true, 'sort_order' => 8, 'is_billable' => true, 'unit_price' => 2000.00],
            ['name' => 'Conteneur 20 pieds', 'is_active' => true, 'sort_order' => 9, 'is_billable' => true, 'unit_price' => 0.00],
            ['name' => 'Conteneur 40 pieds', 'is_active' => true, 'sort_order' => 10, 'is_billable' => true, 'unit_price' => 0.00],
        ];

        foreach ($additional as $type) {
            PackagingType::firstOrCreate(['name' => $type['name']], $type);
        }
    }

    // ─── Méthodes de paiement ──────────────────────────────────────────

    private function seedPaymentMethods(): void
    {
        $this->command?->info('Méthodes de paiement…');

        $libreville = Agency::where('code', 'LBV')->first();
        $portGentil = Agency::where('code', 'POG')->first();
        $paris = Agency::where('code', 'PAR')->first();

        $methodsGabon = [
            ['code' => 'airtel_money', 'name' => 'Airtel Money', 'instructions' => 'Envoyez le montant au +241 077 44 20 00 via Airtel Money. Conservez le SMS de confirmation.', 'currency' => 'XAF', 'sort_order' => 1],
            ['code' => 'moov_money', 'name' => 'Moov Money', 'instructions' => 'Envoyez le montant au +241 066 44 20 00 via Moov Money. Conservez le code de transaction.', 'currency' => 'XAF', 'sort_order' => 2],
            ['code' => 'cash_xaf', 'name' => 'Espèces (FCFA)', 'instructions' => 'Paiement en espèces FCFA au comptoir de votre agence Kotinda.', 'currency' => 'XAF', 'sort_order' => 3],
            ['code' => 'bank_transfer_xaf', 'name' => 'Virement bancaire (FCFA)', 'instructions' => 'Virement vers notre compte BGFIBank Gabon. Les coordonnées vous seront communiquées.', 'currency' => 'XAF', 'sort_order' => 4],
            ['code' => 'card', 'name' => 'Carte bancaire (Visa/Mastercard)', 'instructions' => 'Paiement par carte via le portail sécurisé ou au terminal de l\'agence.', 'currency' => 'XAF', 'sort_order' => 5],
        ];

        $methodsParis = [
            ['code' => 'bank_transfer_eur', 'name' => 'Virement bancaire (EUR)', 'instructions' => 'Virement SEPA vers notre compte bancaire en France.', 'currency' => 'EUR', 'sort_order' => 1],
            ['code' => 'card', 'name' => 'Carte bancaire (Visa/Mastercard)', 'instructions' => 'Paiement par carte via le portail sécurisé.', 'currency' => 'EUR', 'sort_order' => 2],
            ['code' => 'paypal', 'name' => 'PayPal', 'instructions' => 'Paiement via PayPal à payments@kotinda.ga.', 'currency' => 'EUR', 'sort_order' => 3],
        ];

        $byAgency = [
            [$libreville, $methodsGabon],
            [$portGentil, $methodsGabon],
            [$paris, $methodsParis],
        ];

        foreach ($byAgency as [$agency, $methods]) {
            if (! $agency) {
                continue;
            }
            foreach ($methods as $m) {
                $instructions = $agency->code === 'POG'
                    ? str_replace('+241 077 44 20 00', '+241 077 55 30 00', str_replace('+241 066 44 20 00', '+241 066 55 30 00', $m['instructions']))
                    : $m['instructions'];
                PaymentMethod::updateOrCreate(
                    ['agency_id' => $agency->id, 'code' => $m['code']],
                    array_merge($m, ['instructions' => $instructions, 'agency_id' => $agency->id, 'is_active' => true])
                );
            }
        }
    }

    // ─── Coordonnées de paiement par agence ────────────────────────────

    private function seedAgencyPaymentCoordinates(): void
    {
        $this->command?->info('Coordonnées de paiement…');

        $libreville = Agency::where('code', 'LBV')->first();
        $portGentil = Agency::where('code', 'POG')->first();
        $paris = Agency::where('code', 'PAR')->first();

        if ($libreville) {
            AgencyPaymentCoordinate::updateOrCreate(
                ['agency_id' => $libreville->id, 'label' => 'Mobile Money'],
                [
                    'details' => "Airtel Money : +241 077 44 20 00\nMoov Money : +241 066 44 20 00\nBénéficiaire : Kotinda SARL",
                    'sort_order' => 1,
                    'is_active' => true,
                ]
            );
            AgencyPaymentCoordinate::updateOrCreate(
                ['agency_id' => $libreville->id, 'label' => 'Compte bancaire FCFA'],
                [
                    'details' => "Banque : BGFIBank Gabon [À compléter]\nCompte : [À compléter]\nBénéficiaire : Kotinda SARL",
                    'sort_order' => 2,
                    'is_active' => true,
                ]
            );
        }

        if ($portGentil) {
            AgencyPaymentCoordinate::updateOrCreate(
                ['agency_id' => $portGentil->id, 'label' => 'Mobile Money'],
                [
                    'details' => "Airtel Money : +241 077 55 30 00\nMoov Money : +241 066 55 30 00\nBénéficiaire : Kotinda SARL",
                    'sort_order' => 1,
                    'is_active' => true,
                ]
            );
        }

        if ($paris) {
            AgencyPaymentCoordinate::updateOrCreate(
                ['agency_id' => $paris->id, 'label' => 'Compte bancaire EUR (SEPA)'],
                [
                    'details' => "IBAN : [À compléter]\nBIC : [À compléter]\nBénéficiaire : Kotinda\nBanque : [À compléter]",
                    'sort_order' => 1,
                    'is_active' => true,
                ]
            );
        }
    }

    // ─── Taux de change ────────────────────────────────────────────────

    private function seedExchangeRates(): void
    {
        $this->command?->info('Taux de change…');

        $rates = [
            // Parité fixe du franc CFA (BEAC) : 1 EUR = 655,957 XAF
            ['from_currency' => 'EUR', 'to_currency' => 'XAF', 'rate' => 655.957],
            ['from_currency' => 'USD', 'to_currency' => 'XAF', 'rate' => 600.00],
            ['from_currency' => 'CNY', 'to_currency' => 'XAF', 'rate' => 84.00],
            ['from_currency' => 'AED', 'to_currency' => 'XAF', 'rate' => 163.00],
            ['from_currency' => 'GBP', 'to_currency' => 'XAF', 'rate' => 765.00],
            ['from_currency' => 'CAD', 'to_currency' => 'XAF', 'rate' => 440.00],
            ['from_currency' => 'XAF', 'to_currency' => 'EUR', 'rate' => 0.001524],
            ['from_currency' => 'XAF', 'to_currency' => 'USD', 'rate' => 0.001667],
            ['from_currency' => 'EUR', 'to_currency' => 'USD', 'rate' => 1.09],
            ['from_currency' => 'CNY', 'to_currency' => 'USD', 'rate' => 0.14],
            ['from_currency' => 'AED', 'to_currency' => 'USD', 'rate' => 0.27],
        ];

        foreach ($rates as $rate) {
            ExchangeRate::updateOrCreate(
                ['from_currency' => $rate['from_currency'], 'to_currency' => $rate['to_currency']],
                ['rate' => $rate['rate'], 'valid_from' => now()]
            );
        }
    }

    // ─── Catégories d'articles ─────────────────────────────────────────

    private function seedArticleCategories(): void
    {
        $this->command?->info('Catégories d\'articles…');

        $categories = [
            ['name' => 'Électronique & High-Tech', 'description' => 'Téléphones, ordinateurs, tablettes, accessoires tech', 'sort_order' => 1],
            ['name' => 'Vêtements & Mode', 'description' => 'Vêtements, chaussures, accessoires de mode', 'sort_order' => 2],
            ['name' => 'Maison & Ameublement', 'description' => 'Meubles, décoration, literie, éclairage', 'sort_order' => 3],
            ['name' => 'Beauté & Santé', 'description' => 'Cosmétiques, parfums, soins, compléments alimentaires', 'sort_order' => 4],
            ['name' => 'Sport & Loisirs', 'description' => 'Équipements sportifs, fitness, outdoor', 'sort_order' => 5],
            ['name' => 'Livres & Éducation', 'description' => 'Livres, manuels, fournitures scolaires', 'sort_order' => 6],
            ['name' => 'Alimentation & Boissons', 'description' => 'Produits alimentaires autorisés à l\'import', 'sort_order' => 7],
            ['name' => 'Auto & Moto', 'description' => 'Pièces détachées, accessoires automobiles', 'sort_order' => 8],
            ['name' => 'Machines & Équipements industriels', 'description' => 'Machines, outils, équipements professionnels', 'sort_order' => 9],
            ['name' => 'Jouets & Enfants', 'description' => 'Jouets, jeux, articles pour bébé', 'sort_order' => 10],
            ['name' => 'Bijoux & Montres', 'description' => 'Bijoux, montres (valeur < 650 000 FCFA)', 'sort_order' => 11],
            ['name' => 'Audio & Vidéo', 'description' => 'TVs, enceintes, casques, caméras', 'sort_order' => 12],
            ['name' => 'Matériaux de construction', 'description' => 'Pierre, plâtre, ciment, verre, ouvrages similaires', 'sort_order' => 13],
            ['name' => 'Produits chimiques', 'description' => 'Produits chimiques, peintures, vernis (non dangereux)', 'sort_order' => 14],
            ['name' => 'Textile & Tissus', 'description' => 'Textiles, tissus, articles d\'ameublement en tissu', 'sort_order' => 15],
            ['name' => 'Documents & Courrier', 'description' => 'Documents, enveloppes, courrier professionnel', 'sort_order' => 16],
            ['name' => 'Divers', 'description' => 'Articles divers ne rentrant dans aucune autre catégorie', 'sort_order' => 99],
        ];

        foreach ($categories as $cat) {
            ArticleCategory::updateOrCreate(
                ['name' => $cat['name']],
                array_merge($cat, ['is_active' => true])
            );
        }
    }

    // ─── Suppléments de facturation ────────────────────────────────────

    private function seedBillingExtras(): void
    {
        $this->command?->info('Suppléments de facturation…');

        $agencies = Agency::whereIn('code', ['LBV', 'POG'])->get();
        if ($agencies->isEmpty()) {
            return;
        }

        // Montants fixes en XAF (FCFA)
        $extras = [
            ['label' => 'Assurance transport', 'calculation_description' => '2 % de la valeur déclarée', 'type' => 'percentage', 'value' => 2.0000, 'sort_order' => 1],
            ['label' => 'Frais de dédouanement', 'calculation_description' => 'Frais fixes de traitement douanier (hors droits et taxes)', 'type' => 'fixed', 'value' => 15000.0000, 'sort_order' => 2],
            ['label' => 'Emballage spécial', 'calculation_description' => 'Surcoût pour emballage fragile/renforcé', 'type' => 'fixed', 'value' => 10000.0000, 'sort_order' => 3],
            ['label' => 'Livraison porte-à-porte', 'calculation_description' => 'Livraison finale au domicile du client', 'type' => 'fixed', 'value' => 5000.0000, 'sort_order' => 4],
            ['label' => 'Stockage prolongé (par jour)', 'calculation_description' => 'Au-delà de 45 jours gratuits', 'type' => 'fixed', 'value' => 1000.0000, 'sort_order' => 5],
            ['label' => 'Commission achat assisté', 'calculation_description' => '7 % du prix d\'achat (minimum 6 500 FCFA)', 'type' => 'percentage', 'value' => 7.0000, 'sort_order' => 6],
            ['label' => 'Frais de regroupement', 'calculation_description' => 'Consolidation de plusieurs colis', 'type' => 'fixed', 'value' => 3000.0000, 'sort_order' => 7],
            ['label' => 'Taxe carburant', 'calculation_description' => 'Surcharge carburant aérien', 'type' => 'percentage', 'value' => 3.0000, 'sort_order' => 8],
        ];

        foreach ($agencies as $agency) {
            foreach ($extras as $extra) {
                BillingExtra::updateOrCreate(
                    ['agency_id' => $agency->id, 'label' => $extra['label']],
                    array_merge($extra, ['agency_id' => $agency->id, 'is_active' => true])
                );
            }
        }
    }

    // ─── Zones et règles de tarification ───────────────────────────────

    private function seedZonesAndPricingRules(): void
    {
        $this->command?->info('Zones et règles de tarification…');

        $libreville = Agency::where('code', 'LBV')->first();
        $portGentil = Agency::where('code', 'POG')->first();
        if (! $libreville) {
            return;
        }

        // Formules en FCFA (XAF) par kg
        $zones = [
            [
                'agency' => $libreville,
                'name' => 'Zone Libreville Centre (Louis, Glass, Nzeng-Ayong)',
                'polygon' => [[0.43, 9.42], [0.43, 9.48], [0.36, 9.48], [0.36, 9.42]],
                'rules' => [
                    ['name' => 'Livraison Libreville Centre', 'formula' => 'weight_kg * 500 + 2000', 'conditions' => ['min_weight' => 0, 'max_weight' => 50], 'priority' => 1],
                ],
            ],
            [
                'agency' => $libreville,
                'name' => 'Zone Grand Libreville (Akanda, Owendo)',
                'polygon' => [[0.55, 9.36], [0.55, 9.56], [0.26, 9.56], [0.26, 9.36]],
                'rules' => [
                    ['name' => 'Livraison Akanda / Owendo', 'formula' => 'weight_kg * 750 + 3000', 'conditions' => ['min_weight' => 0, 'max_weight' => 50], 'priority' => 1],
                ],
            ],
            [
                'agency' => $portGentil ?? $libreville,
                'name' => 'Zone Port-Gentil',
                'polygon' => [[-0.64, 8.70], [-0.64, 8.83], [-0.78, 8.83], [-0.78, 8.70]],
                'rules' => [
                    ['name' => 'Livraison Port-Gentil', 'formula' => 'weight_kg * 500 + 2000', 'conditions' => ['min_weight' => 0], 'priority' => 1],
                ],
            ],
            [
                'agency' => $libreville,
                'name' => 'Zone intérieur du pays (Franceville, Oyem, Lambaréné…)',
                'polygon' => [],
                'rules' => [
                    ['name' => 'Livraison intérieur du pays', 'formula' => 'weight_kg * 1500 + 5000', 'conditions' => ['min_weight' => 0], 'priority' => 10],
                ],
            ],
        ];

        foreach ($zones as $zoneData) {
            $agency = $zoneData['agency'];
            $zone = Zone::updateOrCreate(
                ['agency_id' => $agency->id, 'name' => $zoneData['name']],
                ['polygon' => $zoneData['polygon'], 'is_active' => true, 'agency_id' => $agency->id]
            );

            foreach ($zoneData['rules'] as $ruleData) {
                PricingRule::updateOrCreate(
                    ['agency_id' => $agency->id, 'zone_id' => $zone->id, 'name' => $ruleData['name']],
                    array_merge($ruleData, ['agency_id' => $agency->id, 'zone_id' => $zone->id, 'is_active' => true])
                );
            }
        }
    }

    // ─── Modèles de lignes de devis ────────────────────────────────────

    private function seedQuoteLineTemplates(): void
    {
        $this->command?->info('Modèles de lignes de devis…');

        $libreville = Agency::where('code', 'LBV')->first();

        // Enum valides → type: percentage|fixed_amount|manual
        //                 calculation_base: product_price|subtotal_after_commission (nullable)
        //                 applies_to: all|assisted_purchase|shipment
        //                 behavior: mandatory|optional|optional_included
        // is_mandatory: si true → cadenas UI, ligne non supprimable. Les 3 lignes « cœur » restent
        // chargées par défaut (behavior mandatory) mais l’opérateur peut les retirer du devis.
        $templates = [
            [
                'internal_code' => 'PRODUCT_COST',
                'name' => 'Coût du produit',
                'description' => 'Prix d\'achat du/des article(s)',
                'type' => 'manual',
                'calculation_base' => null,
                'default_value' => 0,
                'is_mandatory' => false,
                'is_visible_to_client' => true,
                'display_order' => 1,
                'applies_to' => 'all',
                'behavior' => 'mandatory',
            ],
            [
                'internal_code' => 'COMMISSION',
                'name' => 'Commission de service',
                'description' => '7 % du prix d\'achat (minimum 6 500 FCFA)',
                'type' => 'percentage',
                'calculation_base' => 'product_price',
                'default_value' => 7.00,
                'is_mandatory' => false,
                'is_visible_to_client' => true,
                'display_order' => 2,
                'applies_to' => 'assisted_purchase',
                'behavior' => 'mandatory',
            ],
            [
                'internal_code' => 'SHIPPING_INTL',
                'name' => 'Frais d\'expédition internationale',
                'description' => 'Coût du transport international basé sur le poids/volume',
                'type' => 'fixed_amount',
                'calculation_base' => null,
                'default_value' => 10000.00,
                'is_mandatory' => false,
                'is_visible_to_client' => true,
                'display_order' => 3,
                'applies_to' => 'all',
                'behavior' => 'mandatory',
            ],
            [
                'internal_code' => 'CUSTOMS',
                'name' => 'Frais de dédouanement',
                'description' => 'Formalités douanières au Gabon (hors droits et taxes)',
                'type' => 'fixed_amount',
                'calculation_base' => null,
                'default_value' => 15000.00,
                'is_mandatory' => false,
                'is_visible_to_client' => true,
                'display_order' => 4,
                'applies_to' => 'all',
                'behavior' => 'optional_included',
            ],
            [
                'internal_code' => 'INSURANCE',
                'name' => 'Assurance transport',
                'description' => '2% de la valeur déclarée',
                'type' => 'percentage',
                'calculation_base' => 'product_price',
                'default_value' => 2.00,
                'is_mandatory' => false,
                'is_visible_to_client' => true,
                'display_order' => 5,
                'applies_to' => 'all',
                'behavior' => 'optional',
            ],
            [
                'internal_code' => 'PACKAGING',
                'name' => 'Emballage',
                'description' => 'Frais d\'emballage et conditionnement',
                'type' => 'fixed_amount',
                'calculation_base' => null,
                'default_value' => 3000.00,
                'is_mandatory' => false,
                'is_visible_to_client' => true,
                'display_order' => 6,
                'applies_to' => 'all',
                'behavior' => 'optional',
            ],
            [
                'internal_code' => 'DELIVERY_LOCAL',
                'name' => 'Livraison locale (porte-à-porte)',
                'description' => 'Livraison finale au domicile du destinataire',
                'type' => 'fixed_amount',
                'calculation_base' => null,
                'default_value' => 5000.00,
                'is_mandatory' => false,
                'is_visible_to_client' => true,
                'display_order' => 7,
                'applies_to' => 'all',
                'behavior' => 'optional',
            ],
            [
                'internal_code' => 'CONSOLIDATION',
                'name' => 'Frais de regroupement',
                'description' => 'Consolidation de plusieurs achats en un seul envoi',
                'type' => 'fixed_amount',
                'calculation_base' => null,
                'default_value' => 3000.00,
                'is_mandatory' => false,
                'is_visible_to_client' => true,
                'display_order' => 8,
                'applies_to' => 'assisted_purchase',
                'behavior' => 'optional',
            ],
            [
                'internal_code' => 'DISCOUNT',
                'name' => 'Remise',
                'description' => 'Remise commerciale',
                'type' => 'percentage',
                'calculation_base' => 'subtotal_after_commission',
                'default_value' => 0,
                'is_mandatory' => false,
                'is_visible_to_client' => true,
                'display_order' => 9,
                'applies_to' => 'all',
                'behavior' => 'optional',
            ],
        ];

        $portGentil = Agency::where('code', 'POG')->first();
        $paris = Agency::where('code', 'PAR')->first();
        $guangzhou = Agency::where('code', 'GZH')->first();
        $dubai = Agency::where('code', 'DXB')->first();

        $agencies = array_filter([$libreville, $portGentil, $paris, $guangzhou, $dubai]);

        foreach ($agencies as $agency) {
            foreach ($templates as $tpl) {
                QuoteLineTemplate::updateOrCreate(
                    ['internal_code' => $tpl['internal_code'], 'agency_id' => $agency->id],
                    array_merge($tpl, ['agency_id' => $agency->id, 'is_active' => true])
                );
            }
        }
    }

    // ─── Modèles de devis ──────────────────────────────────────────────

    private function seedQuoteTemplates(): void
    {
        $this->command?->info('Modèles de devis…');

        $agencies = Agency::whereIn('code', ['LBV', 'POG', 'PAR', 'GZH', 'DXB'])->where('is_active', true)->get();

        if ($agencies->isEmpty()) {
            return;
        }

        $templates = [
            [
                'name' => 'Devis achat assisté standard',
                'description' => 'Modèle standard pour l\'assistance d\'achat international avec commission, expédition et douane.',
                'lines' => ['PRODUCT_COST', 'COMMISSION', 'SHIPPING_INTL', 'CUSTOMS', 'DELIVERY_LOCAL'],
            ],
            [
                'name' => 'Devis expédition simple',
                'description' => 'Modèle pour envoi de colis sans achat assisté.',
                'lines' => ['SHIPPING_INTL', 'CUSTOMS', 'PACKAGING', 'DELIVERY_LOCAL'],
            ],
            [
                'name' => 'Devis achat assisté premium',
                'description' => 'Modèle complet avec assurance et regroupement inclus.',
                'lines' => ['PRODUCT_COST', 'COMMISSION', 'SHIPPING_INTL', 'CUSTOMS', 'INSURANCE', 'PACKAGING', 'DELIVERY_LOCAL', 'CONSOLIDATION'],
            ],
            [
                'name' => 'Devis achat assisté économique',
                'description' => 'Modèle minimaliste : produit + commission + expédition. Pas de douane ni de livraison locale incluses.',
                'lines' => ['PRODUCT_COST', 'COMMISSION', 'SHIPPING_INTL'],
            ],
            [
                'name' => 'Devis achat assisté avec remise',
                'description' => 'Modèle standard avec remise commerciale appliquée.',
                'lines' => ['PRODUCT_COST', 'COMMISSION', 'SHIPPING_INTL', 'CUSTOMS', 'DELIVERY_LOCAL', 'DISCOUNT'],
            ],
        ];

        foreach ($agencies as $agency) {
            foreach ($templates as $tplData) {
                $template = QuoteTemplate::updateOrCreate(
                    ['agency_id' => $agency->id, 'name' => $tplData['name']],
                    [
                        'agency_id' => $agency->id,
                        'description' => $tplData['description'],
                        'is_shared' => true,
                        'usage_count' => 0,
                    ]
                );

                $sortOrder = 0;
                foreach ($tplData['lines'] as $lineCode) {
                    $lineTemplate = QuoteLineTemplate::where('internal_code', $lineCode)
                        ->where('agency_id', $agency->id)
                        ->first();

                    if ($lineTemplate) {
                        QuoteTemplateLine::updateOrCreate(
                            ['quote_template_id' => $template->id, 'quote_line_template_id' => $lineTemplate->id],
                            ['sort_order' => $sortOrder++]
                        );
                    }
                }
            }
        }
    }

    // ─── Templates e-mail pour devis ───────────────────────────────────

    private function seedQuoteEmailTemplates(): void
    {
        $this->command?->info('Templates e-mail devis…');

        $agencies = Agency::whereIn('code', ['LBV', 'POG', 'PAR', 'GZH', 'DXB'])->where('is_active', true)->get();

        if ($agencies->isEmpty()) {
            return;
        }

        $allVars = [
            'quote_reference', 'purchase_id', 'client_name', 'client_first_name', 'client_email',
            'total_formatted', 'quote_total', 'total_amount', 'currency', 'currency_symbol',
            'total_secondary', 'secondary_currency', 'quote_link', 'response_url', 'payment_url',
            'validity_days', 'expiry_date', 'expires_at', 'company_phone', 'company_name',
            'site_name', 'site_email', 'company_email', 'estimated_delivery', 'staff_message', 'payment_methods_note',
            'payment_instructions', 'lines_subtotal_formatted', 'service_fee_formatted', 'bank_fee_formatted', 'bank_fee_percentage',
            'accent_color', 'logo_url', 'articles_summary',
        ];

        $templates = [
            [
                'event' => 'quote_sent',
                'subject' => 'Votre devis {{site_name}} n°{{quote_reference}} — {{total_formatted}} {{currency}}',
                'body' => '<p>Bonjour <strong>{{client_first_name}}</strong>,</p>'
                    .'<p>Nous avons le plaisir de vous transmettre votre <strong>devis n°{{quote_reference}}</strong> d\'un montant de <strong>{{total_formatted}} {{currency}}</strong> '
                    .'(équivalent indicatif : <strong>{{total_secondary}} {{secondary_currency}}</strong>).</p>'
                    .'<p>Ce devis est valable <strong>{{validity_days}}</strong> jours. Passé ce délai, il sera automatiquement annulé.</p>'
                    .'<p>Vous trouverez ci-dessous le récapitulatif de votre demande. Le devis complet est également joint à cet e-mail au format PDF.</p>'
                    .'<p>N\'hésitez pas à nous contacter pour toute question.</p>'
                    .'<p>Cordialement,<br>L\'équipe <strong>{{site_name}}</strong></p>',
                'variables' => $allVars,
            ],
            [
                'event' => 'quote_reminder_1',
                'subject' => 'Rappel : Votre devis {{site_name}} n°{{quote_reference}} attend votre réponse',
                'body' => '<p>Bonjour <strong>{{client_first_name}}</strong>,</p>'
                    .'<p>Nous vous rappelons que votre <strong>devis n°{{quote_reference}}</strong> d\'un montant de <strong>{{total_formatted}} {{currency}}</strong> est toujours en attente de votre réponse.</p>'
                    .'<p>Attention, ce devis expire le <strong>{{expiry_date}}</strong>. Passé ce délai, il sera automatiquement annulé.</p>'
                    .'<p style="margin:24px 0;"><a href="{{quote_link}}" style="background-color:{{accent_color}};color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:6px;display:inline-block;font-weight:600;">Consulter et répondre au devis</a></p>'
                    .'<p>Cordialement,<br>L\'équipe <strong>{{site_name}}</strong></p>',
                'variables' => $allVars,
            ],
            [
                'event' => 'quote_reminder_2',
                'subject' => 'Dernier rappel : Votre devis n°{{quote_reference}} expire très bientôt',
                'body' => '<p>Bonjour <strong>{{client_first_name}}</strong>,</p>'
                    .'<p>Ceci est notre dernier rappel concernant votre <strong>devis n°{{quote_reference}}</strong>.</p>'
                    .'<p>Il expire définitivement le <strong>{{expiry_date}}</strong>. Si vous souhaitez confirmer votre achat, nous vous invitons à valider le devis dès maintenant.</p>'
                    .'<p style="margin:24px 0;"><a href="{{quote_link}}" style="background-color:#dc2626;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:6px;display:inline-block;font-weight:600;">Valider mon devis avant expiration</a></p>'
                    .'<p>Cordialement,<br>L\'équipe <strong>{{site_name}}</strong></p>',
                'variables' => $allVars,
            ],
            [
                'event' => 'quote_accepted',
                'subject' => 'Confirmation : Devis #{{quote_reference}} accepté',
                'body' => '<p>Bonjour <strong>{{client_first_name}}</strong>,</p>'
                    .'<p>Merci d\'avoir accepté le <strong>devis n°{{quote_reference}}</strong>.</p>'
                    .'<p>Notre équipe va maintenant procéder à la vérification finale et à la préparation de votre commande. Vous serez informé de l\'avancement à chaque étape.</p>'
                    .'<div style="background-color:#f3f4f6;padding:16px;border-radius:8px;margin:20px 0;">'
                    .'<h4 style="margin:0 0 10px;">Instructions de paiement :</h4>'
                    .'{{payment_instructions}}'
                    .'</div>'
                    .'<p>Cordialement,<br>L\'équipe <strong>{{site_name}}</strong></p>',
                'variables' => $allVars,
            ],
            [
                'event' => 'quote_rejected',
                'subject' => 'Devis #{{quote_reference}} – Nous restons à votre disposition',
                'body' => '<p>Bonjour <strong>{{client_first_name}}</strong>,</p>'
                    .'<p>Nous avons bien pris note que vous ne souhaitez pas donner suite au <strong>devis n°{{quote_reference}}</strong>.</p>'
                    .'<p>Si vous changez d\'avis ou si vous souhaitez modifier certains éléments de votre demande, n\'hésitez pas à nous contacter ou à soumettre une nouvelle demande.</p>'
                    .'<p>Cordialement,<br>L\'équipe <strong>{{site_name}}</strong></p>',
                'variables' => $allVars,
            ],
            [
                'event' => 'payment_received',
                'subject' => 'Paiement reçu – Commande #{{quote_reference}} en cours',
                'body' => '<p>Bonjour <strong>{{client_first_name}}</strong>,</p>'
                    .'<p>Bonne nouvelle ! Nous confirmons la réception de votre paiement pour le <strong>dossier n°{{quote_reference}}</strong>.</p>'
                    .'<p>Votre commande est maintenant officiellement en cours de traitement. Nous allons procéder à l\'achat de vos articles auprès des marchands concernés.</p>'
                    .'<p>Cordialement,<br>L\'équipe <strong>{{site_name}}</strong></p>',
                'variables' => $allVars,
            ],
            [
                'event' => 'quote_expired',
                'subject' => 'Votre devis {{site_name}} n°{{quote_reference}} a expiré',
                'body' => '<p>Bonjour <strong>{{client_first_name}}</strong>,</p>'
                    .'<p>Votre <strong>devis n°{{quote_reference}}</strong> a expiré le <strong>{{expiry_date}}</strong> et a été automatiquement annulé.</p>'
                    .'<p>Si vous êtes toujours intéressé(e) par ces articles, vous pouvez effectuer une nouvelle demande d\'achat assisté depuis votre espace client.</p>'
                    .'<p style="margin:24px 0;"><a href="{{new_request_url}}" style="background-color:{{accent_color}};color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:6px;display:inline-block;font-weight:600;">Nouvelle demande d\'achat</a></p>'
                    .'<p>Cordialement,<br>L\'équipe <strong>{{site_name}}</strong></p>',
                'variables' => array_merge($allVars, ['new_request_url']),
            ],
            [
                'event' => 'order_placed',
                'subject' => 'Commande passée – Votre achat #{{quote_reference}} est en cours',
                'body' => '<p>Bonjour <strong>{{client_first_name}}</strong>,</p>'
                    .'<p>Nous avons le plaisir de vous informer que nous avons passé votre commande auprès du fournisseur pour le <strong>dossier n°{{quote_reference}}</strong>.</p>'
                    .'<p>Nous vous tiendrons informé dès la réception de vos articles dans notre entrepôt de transit.</p>'
                    .'<p>Cordialement,<br>L\'équipe <strong>{{site_name}}</strong></p>',
                'variables' => array_merge($allVars, ['supplier_tracking']),
            ],
            [
                'event' => 'arrived_at_hub',
                'subject' => 'Colis reçu à l\'entrepôt – Dossier #{{quote_reference}}',
                'body' => '<p>Bonjour <strong>{{client_first_name}}</strong>,</p>'
                    .'<p>Vos articles pour le <strong>dossier n°{{quote_reference}}</strong> ont été réceptionnés dans notre entrepôt.</p>'
                    .'<p>Nous préparons maintenant l\'expédition internationale vers votre destination finale.</p>'
                    .'<p>Cordialement,<br>L\'équipe <strong>{{site_name}}</strong></p>',
                'variables' => array_merge($allVars, ['hub_name', 'received_weight']),
            ],
        ];

        foreach ($agencies as $agency) {
            foreach ($templates as $tpl) {
                QuoteEmailTemplate::updateOrCreate(
                    ['agency_id' => $agency->id, 'event' => $tpl['event']],
                    array_merge($tpl, ['agency_id' => $agency->id, 'is_active' => true])
                );
            }
        }
    }

    // ─── Templates de notification ─────────────────────────────────────

    private function seedNotificationTemplates(): void
    {
        $this->command?->info('Templates de notification…');

        $templates = [
            [
                'slug' => 'shipment_created',
                'event_key' => 'shipment.created',
                'title' => 'Nouvel envoi créé',
                'channel' => 'database',
                'channels' => ['database', 'mail'],
                'subject' => 'Votre envoi {{tracking_number}} a été créé',
                'body' => 'Bonjour {{recipient_name}}, votre envoi {{tracking_number}} a été créé et est en attente de dépôt.',
                'sample_variables' => ['tracking_number' => 'KTD-2026-001', 'recipient_name' => 'Christelle Nzé'],
            ],
            [
                'slug' => 'shipment_received_at_hub',
                'event_key' => 'shipment.received_at_hub',
                'title' => 'Colis réceptionné en entrepôt',
                'channel' => 'database',
                'channels' => ['database', 'mail', 'sms'],
                'subject' => 'Votre colis {{tracking_number}} est arrivé dans notre entrepôt',
                'body' => 'Bonjour {{recipient_name}}, votre colis {{tracking_number}} a été réceptionné dans notre entrepôt {{hub_name}}.',
                'sample_variables' => ['tracking_number' => 'KTD-2026-001', 'recipient_name' => 'Christelle Nzé', 'hub_name' => 'Entrepôt Paris'],
            ],
            [
                'slug' => 'shipment_in_transit',
                'event_key' => 'shipment.in_transit',
                'title' => 'Colis en transit',
                'channel' => 'database',
                'channels' => ['database', 'mail'],
                'subject' => 'Votre colis {{tracking_number}} est en route',
                'body' => 'Votre colis {{tracking_number}} est parti de {{origin}} vers {{destination}}. Arrivée estimée : {{eta}}.',
                'sample_variables' => ['tracking_number' => 'KTD-2026-001', 'origin' => 'Paris', 'destination' => 'Libreville', 'eta' => '7-10 jours'],
            ],
            [
                'slug' => 'shipment_customs_hold',
                'event_key' => 'shipment.customs_hold',
                'title' => 'Colis en douane',
                'channel' => 'database',
                'channels' => ['database', 'mail'],
                'subject' => 'Votre colis {{tracking_number}} est en cours de dédouanement',
                'body' => 'Votre colis {{tracking_number}} est actuellement en cours de dédouanement. Nous vous tiendrons informé de l\'avancement.',
                'sample_variables' => ['tracking_number' => 'KTD-2026-001'],
            ],
            [
                'slug' => 'shipment_arrived',
                'event_key' => 'shipment.arrived_at_destination',
                'title' => 'Colis arrivé à destination',
                'channel' => 'database',
                'channels' => ['database', 'mail', 'sms'],
                'subject' => 'Votre colis {{tracking_number}} est arrivé !',
                'body' => 'Bonne nouvelle ! Votre colis {{tracking_number}} est arrivé à {{destination}}. Vous pouvez le récupérer ou planifier une livraison.',
                'sample_variables' => ['tracking_number' => 'KTD-2026-001', 'destination' => 'Libreville'],
            ],
            [
                'slug' => 'shipment_delivered',
                'event_key' => 'shipment.delivered',
                'title' => 'Colis livré',
                'channel' => 'database',
                'channels' => ['database', 'mail', 'sms'],
                'subject' => 'Votre colis {{tracking_number}} a été livré',
                'body' => 'Votre colis {{tracking_number}} a été livré avec succès. Merci d\'avoir choisi Kotinda !',
                'sample_variables' => ['tracking_number' => 'KTD-2026-001'],
            ],
            [
                'slug' => 'pre_alert_confirmed',
                'event_key' => 'pre_alert.confirmed',
                'title' => 'Pré-alerte confirmée',
                'channel' => 'database',
                'channels' => ['database', 'mail'],
                'subject' => 'Pré-alerte {{reference}} confirmée',
                'body' => 'Votre pré-alerte {{reference}} a été confirmée. Nous attendons votre colis dans notre entrepôt.',
                'sample_variables' => ['reference' => 'PA-2026-001'],
            ],
            [
                'slug' => 'assisted_purchase_quote_ready',
                'event_key' => 'assisted_purchase.quoted',
                'title' => 'Devis prêt',
                'channel' => 'database',
                'channels' => ['database', 'mail'],
                'subject' => 'Votre devis d\'achat assisté est prêt',
                'body' => 'Bonjour {{client_name}}, votre devis d\'achat assisté est prêt. Montant total : {{total_amount}} {{currency}}. Consultez-le ici : {{quote_link}}',
                'sample_variables' => ['client_name' => 'Christelle', 'total_amount' => '68 500', 'currency' => 'XAF', 'quote_link' => '#'],
            ],
            [
                'slug' => 'assisted_purchase_ordered',
                'event_key' => 'assisted_purchase.ordered',
                'title' => 'Commande passée',
                'channel' => 'database',
                'channels' => ['database', 'mail'],
                'subject' => 'Votre commande a été passée !',
                'body' => 'Bonjour {{client_name}}, nous avons passé votre commande auprès de {{merchant_name}}. Vous serez informé dès réception dans notre entrepôt.',
                'sample_variables' => ['client_name' => 'Jean', 'merchant_name' => 'Amazon'],
            ],
            [
                'slug' => 'pickup_assigned',
                'event_key' => 'pickup.driver_assigned',
                'title' => 'Livreur assigné',
                'channel' => 'database',
                'channels' => ['database', 'sms'],
                'subject' => 'Un livreur est en route pour votre ramassage',
                'body' => 'Le livreur {{driver_name}} ({{driver_phone}}) viendra chercher votre colis. Contactez-le pour confirmer l\'heure.',
                'sample_variables' => ['driver_name' => 'Junior', 'driver_phone' => '+241 077 30 00 01'],
            ],
            [
                'slug' => 'invoice_created',
                'event_key' => 'invoice.created',
                'title' => 'Nouvelle facture',
                'channel' => 'database',
                'channels' => ['database', 'mail'],
                'subject' => 'Facture #{{invoice_number}} – {{total_amount}} {{currency}}',
                'body' => 'Bonjour {{client_name}}, votre facture #{{invoice_number}} d\'un montant de {{total_amount}} {{currency}} est disponible.',
                'sample_variables' => ['client_name' => 'Christelle', 'invoice_number' => 'INV-2026-001', 'total_amount' => '70 000', 'currency' => 'XAF'],
            ],
            [
                'slug' => 'payment_confirmed',
                'event_key' => 'payment.confirmed',
                'title' => 'Paiement confirmé',
                'channel' => 'database',
                'channels' => ['database', 'mail'],
                'subject' => 'Paiement de {{amount}} {{currency}} confirmé',
                'body' => 'Votre paiement de {{amount}} {{currency}} a été confirmé. Merci !',
                'sample_variables' => ['amount' => '70 000', 'currency' => 'XAF'],
            ],
        ];

        foreach ($templates as $tpl) {
            NotificationTemplate::updateOrCreate(
                ['slug' => $tpl['slug']],
                array_merge($tpl, ['is_active' => true])
            );
        }
    }

    // ─── Utilisateurs démo ─────────────────────────────────────────────

    private function seedDemoUsers(): void
    {
        $this->command?->info('Utilisateurs démo…');

        $libreville = Agency::where('code', 'LBV')->first();
        $portGentil = Agency::where('code', 'POG')->first();
        $paris = Agency::where('code', 'PAR')->first();

        if (! $libreville) {
            return;
        }

        $lockerAddress = static fn (string $code): string => "Kotinda Hub Paris – Casier {$code}, zone de fret Roissy-CDG, 95700 Roissy-en-France, France";

        // Tous les comptes démo utilisent le mot de passe « password ».
        $demoUsers = [
            // Admin d'agence Libreville
            [
                'profile' => [
                    'first_name' => 'Arnaud',
                    'last_name' => 'Obiang',
                    'email' => 'admin.lbv@kotinda.local',
                    'phone' => '+241 077 10 00 01',
                    'address' => 'Boulevard Triomphal Omar Bongo, quartier Louis, Libreville',
                    'agency_id' => $libreville->id,
                    'is_active' => true,
                    'is_staff' => true,
                    'is_client' => false,
                ],
                'location' => ['GA', 'Libreville'],
                'user' => [
                    'name' => 'Arnaud Obiang',
                    'email' => 'admin.lbv@kotinda.local',
                    'password' => 'password',
                    'agency_id' => $libreville->id,
                    'theme_preference' => 'light',
                ],
                'role' => 'agency_admin',
            ],
            // Admin d'agence Port-Gentil
            [
                'profile' => [
                    'first_name' => 'Sandrine',
                    'last_name' => 'Moussavou',
                    'email' => 'admin.pog@kotinda.local',
                    'phone' => '+241 066 10 00 02',
                    'address' => 'Avenue Savorgnan de Brazza, quartier Grand Village, Port-Gentil',
                    'agency_id' => $portGentil?->id,
                    'is_active' => true,
                    'is_staff' => true,
                    'is_client' => false,
                ],
                'location' => ['GA', 'Port-Gentil'],
                'user' => [
                    'name' => 'Sandrine Moussavou',
                    'email' => 'admin.pog@kotinda.local',
                    'password' => 'password',
                    'agency_id' => $portGentil?->id,
                    'theme_preference' => 'light',
                ],
                'role' => 'agency_admin',
            ],
            // Admin du hub d'origine Paris
            [
                'profile' => [
                    'first_name' => 'Laetitia',
                    'last_name' => 'Mintsa',
                    'email' => 'admin.par@kotinda.local',
                    'phone' => '+33 6 12 34 56 78',
                    'address' => 'Zone de fret Roissy-CDG, 95700 Roissy-en-France',
                    'agency_id' => $paris?->id,
                    'is_active' => true,
                    'is_staff' => true,
                    'is_client' => false,
                ],
                'location' => ['FR', 'Paris'],
                'user' => [
                    'name' => 'Laetitia Mintsa',
                    'email' => 'admin.par@kotinda.local',
                    'password' => 'password',
                    'agency_id' => $paris?->id,
                    'theme_preference' => 'light',
                ],
                'role' => 'agency_admin',
            ],
            // Opérateur
            [
                'profile' => [
                    'first_name' => 'Davy',
                    'last_name' => 'Ndong',
                    'email' => 'operateur@kotinda.local',
                    'phone' => '+241 077 20 00 01',
                    'address' => 'Nzeng-Ayong, Libreville',
                    'agency_id' => $libreville->id,
                    'is_active' => true,
                    'is_staff' => true,
                    'is_client' => false,
                ],
                'location' => ['GA', 'Libreville'],
                'user' => [
                    'name' => 'Davy Ndong',
                    'email' => 'operateur@kotinda.local',
                    'password' => 'password',
                    'agency_id' => $libreville->id,
                    'theme_preference' => 'system',
                ],
                'role' => 'operator',
            ],
            // Livreur
            [
                'profile' => [
                    'first_name' => 'Junior',
                    'last_name' => 'Mba',
                    'email' => 'livreur@kotinda.local',
                    'phone' => '+241 077 30 00 01',
                    'address' => 'Owendo, Libreville',
                    'agency_id' => $libreville->id,
                    'type' => 'driver',
                    'is_active' => true,
                    'is_staff' => true,
                    'is_client' => false,
                    'vehicle_type' => 'Moto',
                    'vehicle_plate' => 'EB-482-AA',
                    'coverage_zone' => 'Libreville, Akanda, Owendo',
                    'is_available' => true,
                ],
                'location' => ['GA', 'Libreville'],
                'user' => [
                    'name' => 'Junior Mba',
                    'email' => 'livreur@kotinda.local',
                    'password' => 'password',
                    'agency_id' => $libreville->id,
                    'theme_preference' => 'system',
                ],
                'role' => 'driver',
            ],
            // Agent douanier
            [
                'profile' => [
                    'first_name' => 'Hervé',
                    'last_name' => 'Nguema',
                    'email' => 'douane@kotinda.local',
                    'phone' => '+241 077 40 00 01',
                    'address' => 'Port d\'Owendo, Libreville',
                    'agency_id' => $libreville->id,
                    'is_active' => true,
                    'is_staff' => true,
                    'is_client' => false,
                ],
                'location' => ['GA', 'Libreville'],
                'user' => [
                    'name' => 'Hervé Nguema',
                    'email' => 'douane@kotinda.local',
                    'password' => 'password',
                    'agency_id' => $libreville->id,
                    'theme_preference' => 'system',
                ],
                'role' => 'customs_agent',
            ],
            // Agent SAV – Libreville
            [
                'profile' => [
                    'first_name' => 'Gisèle',
                    'last_name' => 'Ella',
                    'email' => 'sav@kotinda.local',
                    'phone' => '+241 077 40 00 02',
                    'address' => 'Boulevard Triomphal, quartier Louis, Libreville',
                    'agency_id' => $libreville->id,
                    'is_active' => true,
                    'is_staff' => true,
                    'is_client' => false,
                ],
                'location' => ['GA', 'Libreville'],
                'user' => [
                    'name' => 'Gisèle Ella',
                    'email' => 'sav@kotinda.local',
                    'password' => 'password',
                    'agency_id' => $libreville->id,
                    'theme_preference' => 'system',
                ],
                'role' => 'sav_agent',
            ],
            // Comptable – Libreville
            [
                'profile' => [
                    'first_name' => 'Patrick',
                    'last_name' => 'Ekomi',
                    'email' => 'comptable@kotinda.local',
                    'phone' => '+241 077 40 00 03',
                    'address' => 'Boulevard Triomphal, quartier Louis, Libreville',
                    'agency_id' => $libreville->id,
                    'is_active' => true,
                    'is_staff' => true,
                    'is_client' => false,
                ],
                'location' => ['GA', 'Libreville'],
                'user' => [
                    'name' => 'Patrick Ekomi',
                    'email' => 'comptable@kotinda.local',
                    'password' => 'password',
                    'agency_id' => $libreville->id,
                    'theme_preference' => 'system',
                ],
                'role' => 'accountant',
            ],
            // Client 1 – Libreville
            [
                'profile' => [
                    'first_name' => 'Christelle',
                    'last_name' => 'Nzé',
                    'email' => 'client1@kotinda.local',
                    'phone' => '+241 077 50 00 01',
                    'address' => 'Rue Pierre Barro, quartier Louis, Libreville',
                    'agency_id' => $libreville->id,
                    'is_active' => true,
                    'is_staff' => false,
                    'is_client' => true,
                ],
                'location' => ['GA', 'Libreville'],
                'user' => [
                    'name' => 'Christelle Nzé',
                    'email' => 'client1@kotinda.local',
                    'password' => 'password',
                    'agency_id' => $libreville->id,
                    'theme_preference' => 'system',
                ],
                'role' => 'client',
                'locker' => [
                    'code' => 'KTD-LBV-0001',
                    'formatted_address' => $lockerAddress('KTD-LBV-0001'),
                ],
            ],
            // Client 2 – Libreville (Akanda)
            [
                'profile' => [
                    'first_name' => 'Ghislain',
                    'last_name' => 'Ondo',
                    'email' => 'client2@kotinda.local',
                    'phone' => '+241 066 50 00 02',
                    'address' => 'Angondjé, Akanda (Grand Libreville)',
                    'agency_id' => $libreville->id,
                    'is_active' => true,
                    'is_staff' => false,
                    'is_client' => true,
                ],
                'location' => ['GA', 'Libreville'],
                'user' => [
                    'name' => 'Ghislain Ondo',
                    'email' => 'client2@kotinda.local',
                    'password' => 'password',
                    'agency_id' => $libreville->id,
                    'theme_preference' => 'system',
                ],
                'role' => 'client',
                'locker' => [
                    'code' => 'KTD-LBV-0002',
                    'formatted_address' => $lockerAddress('KTD-LBV-0002'),
                ],
            ],
            // Client 3 – Port-Gentil
            [
                'profile' => [
                    'first_name' => 'Prisca',
                    'last_name' => 'Koumba',
                    'email' => 'client3@kotinda.local',
                    'phone' => '+241 077 50 00 03',
                    'address' => 'Quartier Grand Village, Port-Gentil',
                    'agency_id' => $portGentil?->id,
                    'is_active' => true,
                    'is_staff' => false,
                    'is_client' => true,
                ],
                'location' => ['GA', 'Port-Gentil'],
                'user' => [
                    'name' => 'Prisca Koumba',
                    'email' => 'client3@kotinda.local',
                    'password' => 'password',
                    'agency_id' => $portGentil?->id,
                    'theme_preference' => 'dark',
                ],
                'role' => 'client',
                'locker' => [
                    'code' => 'KTD-POG-0001',
                    'formatted_address' => $lockerAddress('KTD-POG-0001'),
                ],
            ],
        ];

        foreach ($demoUsers as $data) {
            [$iso2, $city] = $data['location'];

            $profile = Profile::updateOrCreate(
                ['email' => $data['profile']['email']],
                array_merge($data['profile'], $this->locate($iso2, $city))
            );

            $user = User::updateOrCreate(
                ['email' => $data['user']['email']],
                array_merge($data['user'], [
                    'profile_id' => $profile->id,
                    'password' => Hash::make($data['user']['password']),
                ])
            );

            $user->syncRoles([$data['role']]);

            if (isset($data['locker'])) {
                Locker::updateOrCreate(
                    ['code' => $data['locker']['code']],
                    array_merge($data['locker'], [
                        'profile_id' => $profile->id,
                        'user_id' => $user->id,
                    ])
                );
            }
        }
    }

    // ─── Achats assistés et devis de démonstration ─────────────────────

    private function seedDemoAssistedPurchases(): void
    {
        $this->command?->info('Achats assistés et devis démo…');

        $client1 = User::where('email', 'client1@kotinda.local')->first();
        $client2 = User::where('email', 'client2@kotinda.local')->first();
        $client3 = User::where('email', 'client3@kotinda.local')->first();
        $operator = User::where('email', 'operateur@kotinda.local')->first();

        if (! $client1 || ! $operator) {
            $this->command?->warn('Utilisateurs démo introuvables, achats assistés ignorés.');
            return;
        }

        $amazon = Merchant::where('name', 'Amazon')->first();
        $aliexpress = Merchant::where('name', 'AliExpress')->first();
        $nike = Merchant::where('name', 'Nike')->first();
        $apple = Merchant::where('name', 'Apple')->first();
        $sephora = Merchant::where('name', 'Sephora')->first();
        $decathlon = Merchant::where('name', 'Decathlon')->first();
        $noon = Merchant::where('name', 'Noon')->first();

        // Montants en XAF (FCFA). Prix marchands convertis : 1 EUR = 655,957 XAF, 1 USD ≈ 600 XAF, 1 AED ≈ 163 XAF.
        // Total = articles + commission 7 % (min 6 500) + fret + frais bancaires 3 % (+ douane / livraison selon le cas).
        $purchases = [
            // 1. Demande en attente de devis
            [
                'parent' => [
                    'user_id' => $client1->id,
                    'status' => AssistedPurchaseStatus::PENDING_QUOTE,
                    'notes' => 'Je voudrais commander ces articles pour un cadeau d\'anniversaire. Livraison à Libreville, quartier Louis.',
                    'product_url' => 'https://www.amazon.fr/dp/B0DFHBFQFB',
                    'article_label' => 'Apple AirPods Pro 2',
                    'quantity' => 1,
                ],
                'items' => [
                    ['merchant_id' => $apple?->id, 'url' => 'https://www.amazon.fr/dp/B0DFHBFQFB', 'name' => 'Apple AirPods Pro 2 (2ème génération) USB-C', 'quantity' => 1, 'unit_price' => null],
                    ['merchant_id' => $amazon?->id, 'url' => 'https://www.amazon.fr/dp/B09V3KXJPB', 'name' => 'Étui de protection AirPods Pro', 'options' => 'Couleur: Noir', 'quantity' => 2, 'unit_price' => null],
                ],
            ],
            // 2. Devis envoyé, en attente de paiement (colis type ≈ 70 000 FCFA)
            [
                'parent' => [
                    'user_id' => $client1->id,
                    'operator_id' => $operator->id,
                    'status' => AssistedPurchaseStatus::AWAITING_PAYMENT,
                    'notes' => 'Chaussures Nike pour mon fils, pointure 42.',
                    'product_url' => 'https://www.nike.com/fr/t/chaussure-air-max-90',
                    'article_label' => 'Nike Air Max 90',
                    'quantity' => 1,
                    'price_displayed' => '79.99',
                    'price_currency' => 'EUR',
                    'quote_amount' => 52470,
                    'quote_currency' => 'XAF',
                    'service_fee' => 3673,
                    'bank_fee_percentage' => 3,
                    'total_amount' => 68500,
                    'quoted_at' => now()->subDays(2),
                    'quote_version' => 1,
                    'quote_expires_at' => now()->addDays(5),
                    'estimated_weight_kg' => 1.2,
                ],
                'items' => [
                    ['merchant_id' => $nike?->id, 'url' => 'https://www.nike.com/fr/t/chaussure-air-max-90', 'name' => 'Nike Air Max 90 - Homme', 'options' => 'Taille: 42, Couleur: Blanc/Noir', 'quantity' => 1, 'unit_price' => 52470],
                ],
                'snapshot' => [
                    'version' => 1,
                    'total_primary' => 68500,
                    'primary_currency' => 'XAF',
                    'estimated_delivery' => '7-10 jours ouvrés (fret aérien Paris – Libreville)',
                    'staff_message' => 'Bonjour, voici votre devis pour les Nike Air Max 90 (79,99 € soit 52 470 FCFA). Le total comprend la commission, le fret aérien (1,2 kg à 8 900 FCFA/kg) et les frais bancaires.',
                    'sent_at' => now()->subDays(2),
                    'expires_at' => now()->addDays(5),
                    'client_response' => 'pending',
                ],
            ],
            // 3. Payé, commande fournisseur passée
            [
                'parent' => [
                    'user_id' => $client2?->id ?? $client1->id,
                    'operator_id' => $operator->id,
                    'status' => AssistedPurchaseStatus::ORDERED,
                    'notes' => 'Lot de produits de beauté pour ma sœur.',
                    'product_url' => 'https://www.sephora.fr/p/palette-ombres',
                    'article_label' => 'Palette Sephora + eau de toilette',
                    'quantity' => 1,
                    'quote_amount' => 42575,
                    'quote_currency' => 'XAF',
                    'service_fee' => 2980,
                    'bank_fee_percentage' => 3,
                    'total_amount' => 60800,
                    'quoted_at' => now()->subDays(5),
                    'paid_at' => now()->subDays(3),
                    'purchased_at' => now()->subDays(2),
                    'supplier_tracking_number' => '6A12345678901',
                    'quote_version' => 1,
                    'estimated_weight_kg' => 0.8,
                ],
                'items' => [
                    ['merchant_id' => $sephora?->id, 'url' => 'https://www.sephora.fr/p/palette-ombres', 'name' => 'Palette 16 ombres à paupières Sephora Collection', 'quantity' => 1, 'unit_price' => 19615],
                    ['merchant_id' => $sephora?->id, 'url' => 'https://www.sephora.fr/p/eau-de-toilette', 'name' => 'Eau de toilette Sephora Collection 100 ml', 'quantity' => 1, 'unit_price' => 22960],
                ],
                'snapshot' => [
                    'version' => 1,
                    'total_primary' => 60800,
                    'primary_currency' => 'XAF',
                    'estimated_delivery' => '10-14 jours ouvrés',
                    'staff_message' => 'Devis pour vos articles Sephora. Transport aérien depuis Paris, livraison à domicile à Akanda incluse.',
                    'sent_at' => now()->subDays(5),
                    'expires_at' => now()->subDays(5)->addDays(7),
                    'client_response' => 'accepted',
                    'responded_at' => now()->subDays(4),
                ],
                'payments' => [
                    ['amount' => 60800, 'currency' => 'XAF', 'note' => 'Paiement intégral – Airtel Money'],
                ],
            ],
            // 4. Colis arrivé au hub (prêt à convertir en expédition)
            [
                'parent' => [
                    'user_id' => $client1->id,
                    'operator_id' => $operator->id,
                    'status' => AssistedPurchaseStatus::ARRIVED_AT_HUB,
                    'notes' => 'Trottinette électrique pour mes trajets quotidiens à Libreville.',
                    'product_url' => 'https://www.amazon.fr/dp/B0C5EXAMPLE',
                    'article_label' => 'Trottinette électrique intelligente',
                    'quantity' => 1,
                    'price_displayed' => '299.00',
                    'price_currency' => 'EUR',
                    'quote_amount' => 196131,
                    'quote_currency' => 'XAF',
                    'service_fee' => 13729,
                    'bank_fee_percentage' => 3,
                    'total_amount' => 325000,
                    'quoted_at' => now()->subDays(15),
                    'paid_at' => now()->subDays(12),
                    'purchased_at' => now()->subDays(10),
                    'supplier_tracking_number' => 'TBA999888777666',
                    'quote_version' => 1,
                    'estimated_weight_kg' => 15.0,
                    'hub_received_weight_kg' => 14.500,
                ],
                'items' => [
                    ['merchant_id' => $amazon?->id, 'url' => 'https://www.amazon.fr/dp/B0C5EXAMPLE', 'name' => 'Trottinette électrique pliable 350W', 'options' => 'Couleur: Noir, Autonomie: 30km', 'quantity' => 1, 'unit_price' => 196131],
                ],
                'snapshot' => [
                    'version' => 1,
                    'total_primary' => 325000,
                    'primary_currency' => 'XAF',
                    'estimated_delivery' => '15-20 jours ouvrés',
                    'staff_message' => 'Devis trottinette électrique. Article volumineux, fret aérien +10 kg à 7 500 FCFA/kg.',
                    'sent_at' => now()->subDays(15),
                    'expires_at' => now()->subDays(8),
                    'client_response' => 'accepted',
                    'responded_at' => now()->subDays(13),
                ],
                'payments' => [
                    ['amount' => 150000, 'currency' => 'XAF', 'note' => 'Acompte – Airtel Money'],
                    ['amount' => 175000, 'currency' => 'XAF', 'note' => 'Solde – Moov Money'],
                ],
            ],
            // 5. Devis envoyé à une cliente de Port-Gentil (fret maritime)
            [
                'parent' => [
                    'user_id' => $client3?->id ?? $client1->id,
                    'operator_id' => $operator->id,
                    'status' => AssistedPurchaseStatus::QUOTED,
                    'notes' => 'Équipement sportif pour ma salle de sport à Port-Gentil.',
                    'product_url' => 'https://www.decathlon.fr/p/halteres-20kg',
                    'article_label' => 'Kit haltères Decathlon + tapis',
                    'quantity' => 1,
                    'quote_amount' => 78689,
                    'quote_currency' => 'XAF',
                    'service_fee' => 5508,
                    'bank_fee_percentage' => 3,
                    'total_amount' => 174200,
                    'quoted_at' => now()->subDay(),
                    'quote_version' => 1,
                    'quote_expires_at' => now()->addDays(6),
                    'estimated_weight_kg' => 25.0,
                ],
                'items' => [
                    ['merchant_id' => $decathlon?->id, 'url' => 'https://www.decathlon.fr/p/halteres-20kg', 'name' => 'Kit haltères 20 kg réglables', 'quantity' => 1, 'unit_price' => 52470],
                    ['merchant_id' => $decathlon?->id, 'url' => 'https://www.decathlon.fr/p/tapis-fitness', 'name' => 'Tapis de sol fitness 180 x 60 cm', 'quantity' => 1, 'unit_price' => 13113],
                    ['merchant_id' => $decathlon?->id, 'url' => 'https://www.decathlon.fr/p/bande-resistance', 'name' => 'Lot de 3 bandes de résistance élastiques', 'quantity' => 2, 'unit_price' => 6553],
                ],
                'snapshot' => [
                    'version' => 1,
                    'total_primary' => 174200,
                    'primary_currency' => 'XAF',
                    'estimated_delivery' => '5-8 semaines (fret maritime Le Havre – Owendo, puis Port-Gentil)',
                    'staff_message' => 'Devis pour votre équipement sportif Decathlon. Poids important (25 kg) : fret maritime en groupage recommandé (3 500 FCFA/kg).',
                    'sent_at' => now()->subDay(),
                    'expires_at' => now()->addDays(6),
                    'client_response' => 'pending',
                ],
            ],
            // 6. Commande AliExpress (client2) – payée
            [
                'parent' => [
                    'user_id' => $client2?->id ?? $client1->id,
                    'operator_id' => $operator->id,
                    'status' => AssistedPurchaseStatus::PAID,
                    'notes' => 'Accessoires téléphone.',
                    'product_url' => 'https://aliexpress.com/item/10050.html',
                    'article_label' => 'Coques + protections écran Samsung',
                    'quantity' => 5,
                    'quote_amount' => 21294,
                    'quote_currency' => 'XAF',
                    'service_fee' => 6500,
                    'bank_fee_percentage' => 3,
                    'total_amount' => 38100,
                    'quoted_at' => now()->subDays(3),
                    'paid_at' => now()->subDay(),
                    'quote_version' => 1,
                    'estimated_weight_kg' => 0.5,
                ],
                'items' => [
                    ['merchant_id' => $aliexpress?->id, 'url' => 'https://aliexpress.com/item/10050.html', 'name' => 'Coque Samsung Galaxy S24 Ultra anti-choc', 'quantity' => 2, 'unit_price' => 5100],
                    ['merchant_id' => $aliexpress?->id, 'url' => 'https://aliexpress.com/item/10051.html', 'name' => 'Verre trempé Samsung Galaxy S24 Ultra (lot de 3)', 'quantity' => 1, 'unit_price' => 3594],
                    ['merchant_id' => $aliexpress?->id, 'url' => 'https://aliexpress.com/item/10052.html', 'name' => 'Câble USB-C charge rapide 2m (lot de 2)', 'quantity' => 2, 'unit_price' => 3750],
                ],
                'snapshot' => [
                    'version' => 1,
                    'total_primary' => 38100,
                    'primary_currency' => 'XAF',
                    'estimated_delivery' => '15-25 jours (expédition depuis la Chine)',
                    'staff_message' => 'Devis accessoires Samsung depuis AliExpress. Regroupement en un seul colis au hub de Guangzhou.',
                    'sent_at' => now()->subDays(3),
                    'expires_at' => now()->addDays(4),
                    'client_response' => 'accepted',
                    'responded_at' => now()->subDays(2),
                ],
                'payments' => [
                    ['amount' => 38100, 'currency' => 'XAF', 'note' => 'Paiement intégral – Moov Money'],
                ],
            ],
            // 7. Devis expiré (pour historique)
            [
                'parent' => [
                    'user_id' => $client1->id,
                    'operator_id' => $operator->id,
                    'status' => AssistedPurchaseStatus::EXPIRED,
                    'notes' => 'Demande de devis pour un PC portable gaming.',
                    'product_url' => 'https://www.amazon.fr/dp/B0DLEXAMPLE',
                    'article_label' => 'PC portable ASUS ROG Strix',
                    'quantity' => 1,
                    'price_displayed' => '1299.00',
                    'price_currency' => 'EUR',
                    'quote_amount' => 852088,
                    'quote_currency' => 'XAF',
                    'service_fee' => 59646,
                    'bank_fee_percentage' => 3,
                    'total_amount' => 987300,
                    'quoted_at' => now()->subDays(30),
                    'quote_version' => 1,
                    'quote_expires_at' => now()->subDays(23),
                    'estimated_weight_kg' => 3.5,
                ],
                'items' => [
                    ['merchant_id' => $amazon?->id, 'url' => 'https://www.amazon.fr/dp/B0DLEXAMPLE', 'name' => 'ASUS ROG Strix G16 - RTX 4060 - 16 Go RAM', 'quantity' => 1, 'unit_price' => 852088],
                ],
                'snapshot' => [
                    'version' => 1,
                    'total_primary' => 987300,
                    'primary_currency' => 'XAF',
                    'estimated_delivery' => '7-10 jours ouvrés',
                    'staff_message' => 'Devis PC portable ASUS ROG. Article de haute valeur, assurance transport (2 %) incluse.',
                    'sent_at' => now()->subDays(30),
                    'expires_at' => now()->subDays(23),
                    'client_response' => 'pending',
                ],
            ],
            // 8. Annulé par le client
            [
                'parent' => [
                    'user_id' => $client2?->id ?? $client1->id,
                    'status' => AssistedPurchaseStatus::CANCELLED,
                    'notes' => 'Finalement j\'ai trouvé le produit à Libreville, au marché de Mont-Bouët.',
                    'product_url' => 'https://www.amazon.fr/dp/B0CJBLTUNE',
                    'article_label' => 'Casque Bluetooth JBL',
                    'quantity' => 1,
                ],
                'items' => [
                    ['merchant_id' => $amazon?->id, 'url' => 'https://www.amazon.fr/dp/B0CJBLTUNE', 'name' => 'JBL Tune 720BT Casque sans fil', 'quantity' => 1, 'unit_price' => null],
                ],
            ],
            // 9. Devis depuis Dubaï (Noon)
            [
                'parent' => [
                    'user_id' => $client2?->id ?? $client1->id,
                    'operator_id' => $operator->id,
                    'status' => AssistedPurchaseStatus::QUOTED,
                    'notes' => 'Téléphone pour mon épouse, version double SIM.',
                    'product_url' => 'https://www.noon.com/uae-en/galaxy-a55',
                    'article_label' => 'Samsung Galaxy A55 5G',
                    'quantity' => 1,
                    'price_displayed' => '1199.00',
                    'price_currency' => 'AED',
                    'quote_amount' => 195437,
                    'quote_currency' => 'XAF',
                    'service_fee' => 13681,
                    'bank_fee_percentage' => 3,
                    'total_amount' => 223300,
                    'quoted_at' => now()->subHours(6),
                    'quote_version' => 1,
                    'quote_expires_at' => now()->addDays(7),
                    'estimated_weight_kg' => 0.6,
                ],
                'items' => [
                    ['merchant_id' => $noon?->id, 'url' => 'https://www.noon.com/uae-en/galaxy-a55', 'name' => 'Samsung Galaxy A55 5G 256 Go double SIM', 'options' => 'Couleur: Bleu nuit', 'quantity' => 1, 'unit_price' => 195437],
                ],
                'snapshot' => [
                    'version' => 1,
                    'total_primary' => 223300,
                    'primary_currency' => 'XAF',
                    'estimated_delivery' => '6-9 jours ouvrés (fret aérien Dubaï – Libreville)',
                    'staff_message' => 'Devis Samsung Galaxy A55 acheté sur Noon (1 199 AED). Fret aérien depuis notre hub de Dubaï.',
                    'sent_at' => now()->subHours(6),
                    'expires_at' => now()->addDays(7),
                    'client_response' => 'pending',
                ],
            ],
        ];

        foreach ($purchases as $purchaseData) {
            $purchase = AssistedPurchase::create($purchaseData['parent']);

            foreach ($purchaseData['items'] as $item) {
                AssistedPurchaseItem::create(array_merge($item, [
                    'assisted_purchase_id' => $purchase->id,
                ]));
            }

            if (isset($purchaseData['snapshot'])) {
                $snapshotLines = collect($purchaseData['items'])->map(fn($it, $i) => [
                    'position' => $i + 1,
                    'name' => $it['name'],
                    'quantity' => $it['quantity'],
                    'unit_price' => $it['unit_price'] ?? 0,
                    'total' => ($it['unit_price'] ?? 0) * ($it['quantity'] ?? 1),
                ])->values()->toArray();

                QuoteSnapshot::create(array_merge($purchaseData['snapshot'], [
                    'assisted_purchase_id' => $purchase->id,
                    'created_by' => $operator->id,
                    'response_token' => Str::random(64),
                    'snapshot_data' => json_encode([
                        'lines' => $snapshotLines,
                        'service_fee' => $purchaseData['parent']['service_fee'] ?? 0,
                        'bank_fee_percentage' => $purchaseData['parent']['bank_fee_percentage'] ?? 0,
                    ]),
                    'articles_data' => json_encode($snapshotLines),
                ]));
            }

            if (isset($purchaseData['payments'])) {
                foreach ($purchaseData['payments'] as $payment) {
                    AssistedPurchasePayment::create(array_merge($payment, [
                        'assisted_purchase_id' => $purchase->id,
                        'recorded_by' => $operator->id,
                    ]));
                }
            }
        }

        $this->command?->info(count($purchases).' achats assistés démo créés.');
    }
}
