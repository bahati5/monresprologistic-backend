<?php

namespace Database\Seeders;

use App\Enums\AssistedPurchaseStatus;
use App\Enums\PickupStatus;
use App\Enums\RefundStatus;
use App\Enums\SavTicketCategory;
use App\Enums\SavTicketPriority;
use App\Enums\SavTicketStatus;
use App\Enums\ShipmentStatus;
use App\Models\AddressBook;
use App\Models\Agency;
use App\Models\ArticleCategory;
use App\Models\AssistedPurchase;
use App\Models\Comment;
use App\Models\CustomerPackage;
use App\Models\Hub;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\Locker;
use App\Models\NewsletterSubscriber;
use App\Models\Notification;
use App\Models\Pickup;
use App\Models\PreAlert;
use App\Models\Profile;
use App\Models\Refund;
use App\Models\Regroupement;
use App\Models\SavQuickReply;
use App\Models\SavTicket;
use App\Models\SavTicketMessage;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\ShipmentLog;
use App\Models\ShipmentPayment;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Données transactionnelles massives pour la démo vidéo Kotinda (Gabon).
 *
 * Pré-requis : DatabaseSeeder (FullPlatformSeeder + RbacSeeder) déjà exécuté.
 *
 * Couvre : clients supplémentaires, profils destinataires, carnets d'adresses,
 * pré-alertes, colis clients, expéditions (tous statuts), items, logs,
 * regroupements, pickups, factures, paiements, écritures comptables,
 * tickets SAV + messages, réponses rapides SAV, remboursements,
 * notifications, abonnés newsletter, commentaires.
 *
 * Montants facturés en XAF (FCFA) : 1 EUR = 655,957 XAF, 1 USD ≈ 600 XAF, 1 AED ≈ 163 XAF.
 * Tous les comptes démo utilisent le mot de passe « password ».
 *
 * Usage : php artisan db:seed --class=DemoDataSeeder
 */
class DemoDataSeeder extends Seeder
{
    private Agency $libreville;
    private ?Agency $portGentil;
    private ?Agency $paris;
    private ?Agency $guangzhou;
    private ?Agency $dubai;

    private User $superAdmin;
    private User $adminLbv;
    private User $adminPar;
    private User $operator;
    private User $driver;
    private User $customs;
    private User $client1;
    private User $client2;
    private User $client3;

    /** @var array<string, User> */
    private array $extraClients = [];

    /** @var array<string, Profile> */
    private array $recipientProfiles = [];

    private ?int $countryGA;
    private ?int $countryFR;
    private ?int $countryCN;
    private ?int $countryAE;
    private ?int $countryUS;

    public function run(): void
    {
        $this->loadReferences();
        $this->seedExtraClients();

        $this->seedRecipientProfiles();
        $this->seedAddressBooks();
        $this->seedPreAlerts();
        $this->seedCustomerPackages();
        $this->seedShipments();
        $this->seedRegroupements();
        $this->seedPickups();
        $this->seedInvoicesAndPayments();
        $this->seedLedgerEntries();
        $this->seedSavQuickReplies();
        $this->seedSavTickets();
        $this->seedRefunds();
        $this->seedNotifications();
        $this->seedNewsletter();
        $this->seedComments();

        $this->command?->info('DemoDataSeeder terminé — toutes les tables transactionnelles sont remplies.');
    }

    private function loadReferences(): void
    {
        $this->libreville = Agency::where('code', 'LBV')->firstOrFail();
        $this->portGentil = Agency::where('code', 'POG')->first();
        $this->paris = Agency::where('code', 'PAR')->first();
        $this->guangzhou = Agency::where('code', 'GZH')->first();
        $this->dubai = Agency::where('code', 'DXB')->first();

        $this->superAdmin = User::where('email', env('SEED_SUPER_ADMIN_EMAIL', 'admin@kotinda.local'))->firstOrFail();
        $this->adminLbv = User::where('email', 'admin.lbv@kotinda.local')->firstOrFail();
        $this->adminPar = User::where('email', 'admin.par@kotinda.local')->firstOrFail();
        $this->operator = User::where('email', 'operateur@kotinda.local')->firstOrFail();
        $this->driver = User::where('email', 'livreur@kotinda.local')->firstOrFail();
        $this->customs = User::where('email', 'douane@kotinda.local')->firstOrFail();
        $this->client1 = User::where('email', 'client1@kotinda.local')->firstOrFail();
        $this->client2 = User::where('email', 'client2@kotinda.local')->firstOrFail();
        $this->client3 = User::where('email', 'client3@kotinda.local')->firstOrFail();

        $this->countryGA = DB::table('countries')->where('iso2', 'GA')->value('id');
        $this->countryFR = DB::table('countries')->where('iso2', 'FR')->value('id');
        $this->countryCN = DB::table('countries')->where('iso2', 'CN')->value('id');
        $this->countryAE = DB::table('countries')->where('iso2', 'AE')->value('id');
        $this->countryUS = DB::table('countries')->where('iso2', 'US')->value('id');
    }

    /**
     * Identifiants pays / région / ville depuis le référentiel géographique.
     *
     * @return array{country_id: ?int, state_id: ?int, city_id: ?int}
     */
    private function locate(string $iso2, string $cityName): array
    {
        $countryId = DB::table('countries')->where('iso2', $iso2)->value('id');
        $city = $countryId
            ? DB::table('cities')->where('country_id', $countryId)->where('name', $cityName)->first(['id', 'state_id'])
            : null;

        return [
            'country_id' => $countryId,
            'state_id' => $city?->state_id,
            'city_id' => $city?->id,
        ];
    }

    // ─── Clients supplémentaires (pour volume analytics) ────────────────

    private function seedExtraClients(): void
    {
        $this->command?->info('Clients supplémentaires…');

        $clients = [
            ['first' => 'Aurélie', 'last' => 'Mba', 'email' => 'aurelie.mba@kotinda.local', 'phone' => '+241 077 60 00 01', 'address' => 'Quartier Glass, Libreville', 'city' => ['GA', 'Libreville'], 'agency' => $this->libreville, 'locker' => 'KTD-LBV-0003'],
            ['first' => 'Fabrice', 'last' => 'Nguema', 'email' => 'fabrice.nguema@kotinda.local', 'phone' => '+241 066 60 00 02', 'address' => 'Owendo, cité SNI', 'city' => ['GA', 'Owendo'], 'agency' => $this->libreville, 'locker' => 'KTD-LBV-0004'],
            ['first' => 'Nadège', 'last' => 'Mouélé', 'email' => 'nadege.mouele@kotinda.local', 'phone' => '+241 077 60 00 03', 'address' => 'Nzeng-Ayong, Libreville', 'city' => ['GA', 'Libreville'], 'agency' => $this->libreville, 'locker' => 'KTD-LBV-0005'],
            ['first' => 'Yannick', 'last' => 'Bongo', 'email' => 'yannick.bongo@kotinda.local', 'phone' => '+241 066 60 00 04', 'address' => 'Cap Estérias, Akanda', 'city' => ['GA', 'Libreville'], 'agency' => $this->libreville, 'locker' => 'KTD-LBV-0006'],
            ['first' => 'Estelle', 'last' => 'Mintsa', 'email' => 'estelle.mintsa@kotinda.local', 'phone' => '+241 077 60 00 05', 'address' => 'Quartier Louis, Libreville', 'city' => ['GA', 'Libreville'], 'agency' => $this->libreville, 'locker' => 'KTD-LBV-0007'],
            ['first' => 'Carine', 'last' => 'Moussavou', 'email' => 'carine.moussavou@kotinda.local', 'phone' => '+241 077 60 00 06', 'address' => 'Quartier Balise, Port-Gentil', 'city' => ['GA', 'Port-Gentil'], 'agency' => $this->portGentil, 'locker' => 'KTD-POG-0002'],
            ['first' => 'Rodrigue', 'last' => 'Koumba', 'email' => 'rodrigue.koumba@kotinda.local', 'phone' => '+241 066 60 00 07', 'address' => 'Quartier Sindara, Port-Gentil', 'city' => ['GA', 'Port-Gentil'], 'agency' => $this->portGentil, 'locker' => 'KTD-POG-0003'],
            ['first' => 'Murielle', 'last' => 'Ndong', 'email' => 'murielle.ndong@kotinda.local', 'phone' => '+33 6 11 22 33 01', 'address' => 'Rue de la Roquette, 75011 Paris', 'city' => ['FR', 'Paris'], 'agency' => $this->paris, 'locker' => 'KTD-PAR-0001'],
            ['first' => 'Landry', 'last' => 'Obiang', 'email' => 'landry.obiang@kotinda.local', 'phone' => '+33 6 11 22 33 02', 'address' => 'Avenue Jean Jaurès, 75019 Paris', 'city' => ['FR', 'Paris'], 'agency' => $this->paris, 'locker' => 'KTD-PAR-0002'],
        ];

        foreach ($clients as $c) {
            $agency = $c['agency'];
            if (! $agency) {
                continue;
            }

            $profile = Profile::updateOrCreate(
                ['email' => $c['email']],
                array_merge(
                    ['first_name' => $c['first'], 'last_name' => $c['last'], 'phone' => $c['phone'], 'address' => $c['address'], 'agency_id' => $agency->id, 'is_active' => true, 'is_staff' => false, 'is_client' => true],
                    $this->locate(...$c['city'])
                )
            );

            $user = User::updateOrCreate(
                ['email' => $c['email']],
                ['name' => $c['first'].' '.$c['last'], 'password' => Hash::make('password'), 'profile_id' => $profile->id, 'agency_id' => $agency->id, 'theme_preference' => 'system']
            );

            $user->syncRoles(['client']);

            Locker::updateOrCreate(
                ['code' => $c['locker']],
                ['profile_id' => $profile->id, 'user_id' => $user->id, 'formatted_address' => "Kotinda Hub Paris – Casier {$c['locker']}, zone de fret Roissy-CDG, 95700 Roissy-en-France, France"]
            );

            $this->extraClients[$c['email']] = $user;
        }
    }

    // ─── Profils destinataires (non-utilisateurs) ───────────────────────

    private function seedRecipientProfiles(): void
    {
        $this->command?->info('Profils destinataires…');

        $recipients = [
            ['first' => 'Jean-Pierre', 'last' => 'Nzé', 'email' => 'jp.nze@gmail.com', 'phone' => '+241 077 99 10 01', 'address' => 'Quartier Louis, Libreville', 'city' => ['GA', 'Libreville'], 'agency' => $this->libreville],
            ['first' => 'Pauline', 'last' => 'Ondo', 'email' => 'pauline.ondo@gmail.com', 'phone' => '+241 066 99 10 02', 'address' => 'Angondjé, Akanda', 'city' => ['GA', 'Libreville'], 'agency' => $this->libreville],
            ['first' => 'Gaston', 'last' => 'Moussavou', 'email' => 'gaston.moussavou@gmail.com', 'phone' => '+241 077 99 10 03', 'address' => 'Quartier Grand Village, Port-Gentil', 'city' => ['GA', 'Port-Gentil'], 'agency' => $this->portGentil ?? $this->libreville],
            ['first' => 'Sylvie', 'last' => 'Mintsa', 'email' => 'sylvie.mintsa@gmail.com', 'phone' => '+241 077 99 10 04', 'address' => 'Quartier Glass, Libreville', 'city' => ['GA', 'Libreville'], 'agency' => $this->libreville],
            ['first' => 'Kévin', 'last' => 'Ndong', 'email' => 'kevin.ndong@gmail.com', 'phone' => '+241 066 99 10 05', 'address' => 'Owendo, quartier Alénakiri', 'city' => ['GA', 'Owendo'], 'agency' => $this->libreville],
            ['first' => 'Mireille', 'last' => 'Mouélé', 'email' => 'mireille.mouele@outlook.com', 'phone' => '+33 7 55 10 00 01', 'address' => 'Boulevard Voltaire, 75011 Paris', 'city' => ['FR', 'Paris'], 'agency' => $this->paris],
            ['first' => 'Ludovic', 'last' => 'Nguema', 'email' => 'ludovic.nguema@outlook.com', 'phone' => '+241 077 99 10 07', 'address' => 'Franceville, quartier Potos', 'city' => ['GA', 'Franceville'], 'agency' => $this->libreville],
        ];

        foreach ($recipients as $r) {
            if (! $r['agency']) {
                continue;
            }
            $this->recipientProfiles[$r['email']] = Profile::updateOrCreate(
                ['email' => $r['email']],
                array_merge(
                    ['first_name' => $r['first'], 'last_name' => $r['last'], 'phone' => $r['phone'], 'address' => $r['address'], 'agency_id' => $r['agency']->id, 'is_active' => true, 'is_staff' => false, 'is_client' => false],
                    $this->locate(...$r['city'])
                )
            );
        }
    }

    // ─── Carnets d'adresses ─────────────────────────────────────────────

    private function seedAddressBooks(): void
    {
        $this->command?->info('Carnets d\'adresses…');

        $client1Profile = $this->client1->profile;
        $client2Profile = $this->client2->profile;

        if (! $client1Profile || ! $client2Profile) {
            return;
        }

        $books = [
            ['owner' => $client1Profile->id, 'contact_email' => 'jp.nze@gmail.com', 'alias' => 'Papa', 'is_default' => true],
            ['owner' => $client1Profile->id, 'contact_email' => 'sylvie.mintsa@gmail.com', 'alias' => 'Ma sœur', 'is_default' => false],
            ['owner' => $client2Profile->id, 'contact_email' => 'pauline.ondo@gmail.com', 'alias' => 'Maman', 'is_default' => true],
            ['owner' => $client2Profile->id, 'contact_email' => 'kevin.ndong@gmail.com', 'alias' => 'Cousin', 'is_default' => false],
        ];

        foreach ($books as $b) {
            $contact = $this->recipientProfiles[$b['contact_email']] ?? null;
            if ($contact) {
                AddressBook::updateOrCreate(
                    ['owner_profile_id' => $b['owner'], 'contact_profile_id' => $contact->id],
                    ['alias' => $b['alias'], 'is_default' => $b['is_default']]
                );
            }
        }
    }

    // ─── Pré-alertes ────────────────────────────────────────────────────

    private function seedPreAlerts(): void
    {
        $this->command?->info('Pré-alertes…');

        $locker1 = Locker::where('code', 'KTD-LBV-0001')->first();
        $locker2 = Locker::where('code', 'KTD-LBV-0002')->first();
        $locker3 = Locker::where('code', 'KTD-POG-0001')->first();

        // Valeurs déclarées telles qu'indiquées sur la facture du marchand (devise d'achat).
        $preAlerts = [
            ['reference_code' => 'ASN-0001', 'user_id' => $this->client1->id, 'locker_id' => $locker1?->id, 'status' => ShipmentStatus::PendingDropOff, 'merchant_name' => 'Amazon', 'vendor_tracking_number' => 'TBA302456789', 'carrier_name' => 'DHL', 'description' => 'MacBook Air M3 commandé sur Amazon.fr', 'declared_value' => 1299.00, 'value_currency' => 'EUR', 'declared_weight_kg' => 2.5, 'purchase_date' => now()->subDays(8), 'estimated_arrival_date' => now()->addDays(5)],
            ['reference_code' => 'ASN-0002', 'user_id' => $this->client1->id, 'locker_id' => $locker1?->id, 'status' => ShipmentStatus::ReceivedAtHub, 'merchant_name' => 'Apple', 'vendor_tracking_number' => '1Z999AA10123456784', 'carrier_name' => 'UPS', 'description' => 'iPhone 16 Pro Max 256GB', 'declared_value' => 1199.00, 'value_currency' => 'EUR', 'declared_weight_kg' => 0.5, 'purchase_date' => now()->subDays(15), 'estimated_arrival_date' => now()->subDays(2)],
            ['reference_code' => 'ASN-0003', 'user_id' => $this->client2->id, 'locker_id' => $locker2?->id, 'status' => ShipmentStatus::PendingDropOff, 'merchant_name' => 'Nike', 'vendor_tracking_number' => '420221019205590020113', 'carrier_name' => 'Colissimo', 'description' => 'Nike Air Force 1 x2 paires', 'declared_value' => 220.00, 'value_currency' => 'EUR', 'declared_weight_kg' => 2.0, 'purchase_date' => now()->subDays(5), 'estimated_arrival_date' => now()->addDays(3)],
            ['reference_code' => 'ASN-0004', 'user_id' => $this->client3->id, 'locker_id' => $locker3?->id, 'status' => ShipmentStatus::ReceivedAtHub, 'merchant_name' => 'Decathlon', 'vendor_tracking_number' => 'LP123456789FR', 'carrier_name' => 'Colissimo', 'description' => 'Vélo d\'appartement pliable', 'declared_value' => 349.99, 'value_currency' => 'EUR', 'declared_weight_kg' => 22.0, 'purchase_date' => now()->subDays(12), 'estimated_arrival_date' => now()->subDays(1)],
            ['reference_code' => 'ASN-0005', 'user_id' => $this->client1->id, 'locker_id' => $locker1?->id, 'status' => ShipmentStatus::Delivered, 'merchant_name' => 'Sephora', 'vendor_tracking_number' => 'FR9876543210', 'carrier_name' => 'Colissimo', 'description' => 'Coffret parfums Dior', 'declared_value' => 180.00, 'value_currency' => 'EUR', 'declared_weight_kg' => 1.2, 'purchase_date' => now()->subDays(45), 'estimated_arrival_date' => now()->subDays(30)],
            ['reference_code' => 'ASN-0006', 'user_id' => $this->client2->id, 'locker_id' => $locker2?->id, 'status' => ShipmentStatus::InTransit, 'merchant_name' => 'AliExpress', 'vendor_tracking_number' => 'LY123456789CN', 'carrier_name' => 'China Post', 'description' => 'Lot accessoires Samsung Galaxy', 'declared_value' => 45.00, 'value_currency' => 'USD', 'declared_weight_kg' => 0.3, 'purchase_date' => now()->subDays(20), 'estimated_arrival_date' => now()->addDays(10)],
            ['reference_code' => 'ASN-0007', 'user_id' => $this->extraClients['aurelie.mba@kotinda.local']?->id, 'locker_id' => Locker::where('code', 'KTD-LBV-0003')->first()?->id, 'status' => ShipmentStatus::PendingDropOff, 'merchant_name' => 'Zara', 'vendor_tracking_number' => 'ZRA20260501001', 'carrier_name' => 'DPD', 'description' => 'Vêtements collection été 2026', 'declared_value' => 150.00, 'value_currency' => 'EUR', 'declared_weight_kg' => 3.0, 'purchase_date' => now()->subDays(3), 'estimated_arrival_date' => now()->addDays(7)],
            ['reference_code' => 'ASN-0008', 'user_id' => $this->extraClients['fabrice.nguema@kotinda.local']?->id, 'locker_id' => Locker::where('code', 'KTD-LBV-0004')->first()?->id, 'status' => ShipmentStatus::Expired, 'merchant_name' => 'eBay', 'vendor_tracking_number' => null, 'carrier_name' => null, 'description' => 'Pièce détachée Toyota Land Cruiser (non déposée)', 'declared_value' => 85.00, 'value_currency' => 'EUR', 'declared_weight_kg' => 4.0, 'purchase_date' => now()->subDays(60), 'estimated_arrival_date' => now()->subDays(50)],
        ];

        foreach ($preAlerts as $data) {
            PreAlert::updateOrCreate(['reference_code' => $data['reference_code']], $data);
        }
    }

    // ─── Colis Clients (CustomerPackages) ───────────────────────────────

    private function seedCustomerPackages(): void
    {
        $this->command?->info('Colis clients…');

        $locker1 = Locker::where('code', 'KTD-LBV-0001')->first();
        $locker2 = Locker::where('code', 'KTD-LBV-0002')->first();
        $preAlert2 = PreAlert::where('reference_code', 'ASN-0002')->first();
        $preAlert5 = PreAlert::where('reference_code', 'ASN-0005')->first();

        // Montants en XAF (valeur convertie au taux fixe 1 EUR = 655,957 XAF, fret au tarif Paris → Libreville).
        $packages = [
            ['reference_code' => 'PKG-0001', 'user_id' => $this->client1->id, 'agency_id' => $this->paris?->id ?? $this->libreville->id, 'locker_id' => $locker1?->id, 'pre_alert_id' => $preAlert2?->id, 'status' => ShipmentStatus::ReceivedAtHub, 'description' => 'iPhone 16 Pro Max 256GB', 'merchant_name' => 'Apple', 'weight_kg' => 0.480, 'length_cm' => 20, 'width_cm' => 15, 'height_cm' => 8, 'declared_value' => 786500, 'value_currency' => 'XAF', 'received_at' => now()->subDays(2), 'received_by' => $this->adminPar->id, 'condition_notes' => 'Emballage intact, scellé d\'origine.'],
            ['reference_code' => 'PKG-0002', 'user_id' => $this->client1->id, 'agency_id' => $this->paris?->id ?? $this->libreville->id, 'locker_id' => $locker1?->id, 'pre_alert_id' => $preAlert5?->id, 'status' => ShipmentStatus::Delivered, 'description' => 'Coffret parfums Dior', 'merchant_name' => 'Sephora', 'weight_kg' => 1.100, 'length_cm' => 30, 'width_cm' => 25, 'height_cm' => 12, 'declared_value' => 118100, 'value_currency' => 'XAF', 'shipping_cost' => 17800, 'total_cost' => 135900, 'received_at' => now()->subDays(30), 'received_by' => $this->adminPar->id],
            ['reference_code' => 'PKG-0003', 'user_id' => $this->client2->id, 'agency_id' => $this->paris?->id ?? $this->libreville->id, 'locker_id' => $locker2?->id, 'status' => ShipmentStatus::ReceivedAtHub, 'description' => 'Chaussures Nike Air Force 1', 'merchant_name' => 'Nike', 'weight_kg' => 1.800, 'length_cm' => 35, 'width_cm' => 25, 'height_cm' => 15, 'declared_value' => 72200, 'value_currency' => 'XAF', 'received_at' => now()->subDays(1), 'received_by' => $this->adminPar->id],
            ['reference_code' => 'PKG-0004', 'user_id' => $this->client3->id, 'agency_id' => $this->paris?->id ?? $this->libreville->id, 'locker_id' => Locker::where('code', 'KTD-POG-0001')->first()?->id, 'status' => ShipmentStatus::ReadyForDispatch, 'description' => 'Vélo d\'appartement pliable', 'merchant_name' => 'Decathlon', 'weight_kg' => 21.500, 'length_cm' => 120, 'width_cm' => 45, 'height_cm' => 60, 'declared_value' => 229600, 'value_currency' => 'XAF', 'shipping_cost' => 75250, 'total_cost' => 304850, 'received_at' => now()->subDays(1), 'received_by' => $this->adminPar->id],
        ];

        foreach ($packages as $data) {
            CustomerPackage::updateOrCreate(['reference_code' => $data['reference_code']], $data);
        }
    }

    // ─── Expéditions + Items + Logs ─────────────────────────────────────

    private function seedShipments(): void
    {
        $this->command?->info('Expéditions, items et logs…');

        $hubPar = Hub::where('code', 'HUB-PAR')->first();
        $hubGz = Hub::where('code', 'HUB-GZ')->first();
        $hubDxb = Hub::where('code', 'HUB-DXB')->first();
        $hubLbv = Hub::where('code', 'HUB-LBV')->first();
        $hubPog = Hub::where('code', 'HUB-POG')->first();

        $catElec = ArticleCategory::where('name', 'like', 'Électronique%')->first();
        $catVet = ArticleCategory::where('name', 'like', 'Vêtements%')->first();
        $catBeaute = ArticleCategory::where('name', 'like', 'Beauté%')->first();
        $catDoc = ArticleCategory::where('name', 'like', 'Documents%')->first();
        $catMaison = ArticleCategory::where('name', 'like', 'Maison%')->first();
        $catSport = ArticleCategory::where('name', 'like', 'Sport%')->first();
        $catAuto = ArticleCategory::where('name', 'like', 'Auto%')->first();

        $senderProfile1 = $this->client1->profile;
        $senderProfile2 = $this->client2->profile;
        $senderMurielle = $this->extraClients['murielle.ndong@kotinda.local']?->profile;
        $senderLandry = $this->extraClients['landry.obiang@kotinda.local']?->profile;
        $recipPapa = $this->recipientProfiles['jp.nze@gmail.com'] ?? null;
        $recipMaman = $this->recipientProfiles['pauline.ondo@gmail.com'] ?? null;
        $recipTonton = $this->recipientProfiles['gaston.moussavou@gmail.com'] ?? null;
        $recipSoeur = $this->recipientProfiles['sylvie.mintsa@gmail.com'] ?? null;

        // Prix = poids taxable × tarif de la ligne (FCFA/kg) ; valeurs déclarées converties en XAF.
        $shipmentData = [
            // 1. Livré — Paris → Libreville (fret aérien) — il y a 60 jours
            [
                'tracking' => 'EXP2026030001', 'doc' => 'EXP6379850001',
                'sender' => $senderMurielle ?? $senderProfile1, 'recipient' => $recipPapa,
                'creator' => $this->adminPar, 'agency' => $this->paris,
                'origin' => $this->countryFR, 'dest' => $this->countryGA,
                'status' => ShipmentStatus::Delivered, 'flow' => 'standard',
                'hub' => $hubLbv, 'weight' => 3.55, 'vol_w' => 4.0,
                'l' => 40, 'w' => 30, 'h' => 20,
                'value' => 295200, 'currency' => 'XAF', 'price' => 35600,
                'payment_status' => 'paid', 'amount_paid' => 35600, 'paid_at' => now()->subDays(58),
                'items' => [
                    ['desc' => 'Vêtements divers', 'qty' => 5, 'wt' => 2.5, 'val' => 196800, 'cat' => $catVet],
                    ['desc' => 'Chaussures Nike', 'qty' => 2, 'wt' => 1.05, 'val' => 98400, 'cat' => $catVet],
                ],
                'logs' => [
                    ['status' => ShipmentStatus::PendingDropOff, 'title' => 'Expédition créée', 'days_ago' => 65],
                    ['status' => ShipmentStatus::ReceivedAtHub, 'title' => 'Réceptionné à Paris', 'days_ago' => 63],
                    ['status' => ShipmentStatus::ReadyForDispatch, 'title' => 'Prêt à l\'expédition', 'days_ago' => 62],
                    ['status' => ShipmentStatus::InTransit, 'title' => 'En transit FR → GA', 'days_ago' => 61],
                    ['status' => ShipmentStatus::CustomsHold, 'title' => 'Dédouanement à l\'aéroport Léon-Mba', 'days_ago' => 58],
                    ['status' => ShipmentStatus::ArrivedAtDestination, 'title' => 'Arrivé à Libreville', 'days_ago' => 57],
                    ['status' => ShipmentStatus::Delivered, 'title' => 'Livré au destinataire', 'days_ago' => 55],
                ],
                'created_ago' => 65,
            ],
            // 2. En transit — France → Gabon (fret aérien +10 kg)
            [
                'tracking' => 'EXP2026040002', 'doc' => 'EXP6379850002',
                'sender' => $senderProfile1, 'recipient' => $recipMaman,
                'creator' => $this->operator, 'agency' => $this->libreville,
                'origin' => $this->countryFR, 'dest' => $this->countryGA,
                'status' => ShipmentStatus::InTransit, 'flow' => 'standard',
                'hub' => $hubPar, 'weight' => 8.2, 'vol_w' => 12.0,
                'l' => 60, 'w' => 40, 'h' => 25,
                'value' => 557600, 'currency' => 'XAF', 'price' => 90000,
                'payment_status' => 'paid', 'amount_paid' => 90000, 'paid_at' => now()->subDays(8),
                'items' => [
                    ['desc' => 'Laptop HP Pavilion 15"', 'qty' => 1, 'wt' => 2.1, 'val' => 426400, 'cat' => $catElec],
                    ['desc' => 'Souris + clavier sans fil Logitech', 'qty' => 1, 'wt' => 0.8, 'val' => 52500, 'cat' => $catElec],
                    ['desc' => 'Sac à dos laptop Samsonite', 'qty' => 1, 'wt' => 1.2, 'val' => 78700, 'cat' => $catVet],
                ],
                'logs' => [
                    ['status' => ShipmentStatus::PendingDropOff, 'title' => 'Expédition créée', 'days_ago' => 12],
                    ['status' => ShipmentStatus::ReceivedAtHub, 'title' => 'Réceptionné à Paris', 'days_ago' => 10],
                    ['status' => ShipmentStatus::ReadyForDispatch, 'title' => 'Prêt à l\'expédition', 'days_ago' => 9],
                    ['status' => ShipmentStatus::InTransit, 'title' => 'En transit FR → GA', 'days_ago' => 7],
                ],
                'created_ago' => 12,
            ],
            // 3. En douane — Chine → Gabon (maritime, groupage)
            [
                'tracking' => 'EXP2026040003', 'doc' => 'EXP6379850003',
                'sender' => $senderProfile2, 'recipient' => $recipTonton,
                'creator' => $this->operator, 'agency' => $this->libreville,
                'origin' => $this->countryCN, 'dest' => $this->countryGA,
                'status' => ShipmentStatus::CustomsHold, 'flow' => 'standard',
                'hub' => $hubGz, 'weight' => 120.0, 'vol_w' => 200.0,
                'l' => 150, 'w' => 100, 'h' => 80,
                'value' => 2100000, 'currency' => 'XAF', 'price' => 500000,
                'payment_status' => 'partial', 'amount_paid' => 250000, 'paid_at' => now()->subDays(30),
                'items' => [
                    ['desc' => 'Groupe électrogène 5kVA', 'qty' => 1, 'wt' => 80, 'val' => 1320000, 'cat' => $catAuto],
                    ['desc' => 'Pièces de rechange groupe', 'qty' => 1, 'wt' => 15, 'val' => 300000, 'cat' => $catAuto],
                    ['desc' => 'Outils atelier complet', 'qty' => 1, 'wt' => 25, 'val' => 480000, 'cat' => $catAuto],
                ],
                'logs' => [
                    ['status' => ShipmentStatus::PendingDropOff, 'title' => 'Expédition créée', 'days_ago' => 45],
                    ['status' => ShipmentStatus::ReceivedAtHub, 'title' => 'Réceptionné à Guangzhou', 'days_ago' => 42],
                    ['status' => ShipmentStatus::InTransit, 'title' => 'Départ maritime Guangzhou → Owendo', 'days_ago' => 38],
                    ['status' => ShipmentStatus::CustomsHold, 'title' => 'Blocage douane au port d\'Owendo', 'days_ago' => 5],
                ],
                'created_ago' => 45,
            ],
            // 4. Prêt à l'expédition — FR → GA (hub Paris)
            [
                'tracking' => 'EXP2026050004', 'doc' => 'EXP6379850004',
                'sender' => $senderLandry ?? $senderProfile1, 'recipient' => $recipSoeur,
                'creator' => $this->adminPar, 'agency' => $this->paris,
                'origin' => $this->countryFR, 'dest' => $this->countryGA,
                'status' => ShipmentStatus::ReadyForDispatch, 'flow' => 'standard',
                'hub' => $hubPar, 'weight' => 5.0, 'vol_w' => 6.5,
                'l' => 50, 'w' => 35, 'h' => 22,
                'value' => 209900, 'currency' => 'XAF', 'price' => 57850,
                'payment_status' => 'paid', 'amount_paid' => 57850, 'paid_at' => now()->subDays(2),
                'items' => [
                    ['desc' => 'Cosmétiques et soins L\'Oréal', 'qty' => 8, 'wt' => 3.5, 'val' => 144300, 'cat' => $catBeaute],
                    ['desc' => 'Bijoux fantaisie Pandora', 'qty' => 3, 'wt' => 0.3, 'val' => 65600, 'cat' => $catBeaute],
                ],
                'logs' => [
                    ['status' => ShipmentStatus::PendingDropOff, 'title' => 'Expédition créée', 'days_ago' => 5],
                    ['status' => ShipmentStatus::ReceivedAtHub, 'title' => 'Réceptionné à Paris', 'days_ago' => 3],
                    ['status' => ShipmentStatus::ReadyForDispatch, 'title' => 'Prêt à l\'expédition', 'days_ago' => 1],
                ],
                'created_ago' => 5,
            ],
            // 5. En attente de dépôt
            [
                'tracking' => 'EXP2026050005', 'doc' => 'EXP6379850005',
                'sender' => $senderProfile1, 'recipient' => $recipPapa,
                'creator' => $this->operator, 'agency' => $this->libreville,
                'origin' => $this->countryFR, 'dest' => $this->countryGA,
                'status' => ShipmentStatus::PendingDropOff, 'flow' => 'standard',
                'hub' => null, 'weight' => 1.5, 'vol_w' => null,
                'l' => 30, 'w' => 22, 'h' => 5,
                'value' => 32800, 'currency' => 'XAF', 'price' => 13350,
                'payment_status' => 'unpaid', 'amount_paid' => 0, 'paid_at' => null,
                'items' => [
                    ['desc' => 'Documents administratifs', 'qty' => 1, 'wt' => 0.5, 'val' => 0, 'cat' => $catDoc],
                    ['desc' => 'Photos de famille encadrées', 'qty' => 3, 'wt' => 1.0, 'val' => 32800, 'cat' => $catMaison],
                ],
                'logs' => [
                    ['status' => ShipmentStatus::PendingDropOff, 'title' => 'Expédition créée', 'days_ago' => 1],
                ],
                'created_ago' => 1,
            ],
            // 6. Livré — il y a 90 jours (historique)
            [
                'tracking' => 'EXP2026020006', 'doc' => 'EXP6379850006',
                'sender' => $senderProfile2, 'recipient' => $recipMaman,
                'creator' => $this->operator, 'agency' => $this->libreville,
                'origin' => $this->countryFR, 'dest' => $this->countryGA,
                'status' => ShipmentStatus::Delivered, 'flow' => 'standard',
                'hub' => $hubLbv, 'weight' => 2.0, 'vol_w' => 2.5,
                'l' => 35, 'w' => 25, 'h' => 15,
                'value' => 131200, 'currency' => 'XAF', 'price' => 22250,
                'payment_status' => 'paid', 'amount_paid' => 22250, 'paid_at' => now()->subDays(88),
                'items' => [
                    ['desc' => 'Médicaments autorisés', 'qty' => 10, 'wt' => 1.5, 'val' => 98400, 'cat' => $catBeaute],
                    ['desc' => 'Compléments alimentaires', 'qty' => 3, 'wt' => 0.5, 'val' => 32800, 'cat' => $catBeaute],
                ],
                'logs' => [
                    ['status' => ShipmentStatus::PendingDropOff, 'title' => 'Expédition créée', 'days_ago' => 95],
                    ['status' => ShipmentStatus::ReceivedAtHub, 'title' => 'Réceptionné', 'days_ago' => 93],
                    ['status' => ShipmentStatus::InTransit, 'title' => 'En transit', 'days_ago' => 92],
                    ['status' => ShipmentStatus::ArrivedAtDestination, 'title' => 'Arrivé', 'days_ago' => 88],
                    ['status' => ShipmentStatus::Delivered, 'title' => 'Livré', 'days_ago' => 86],
                ],
                'created_ago' => 95,
            ],
            // 7. Livré il y a 30 jours (colis type ≈ 70 000 FCFA tout compris)
            [
                'tracking' => 'EXP2026040007', 'doc' => 'EXP6379850007',
                'sender' => $senderProfile1, 'recipient' => $recipSoeur,
                'creator' => $this->operator, 'agency' => $this->libreville,
                'origin' => $this->countryFR, 'dest' => $this->countryGA,
                'status' => ShipmentStatus::Delivered, 'flow' => 'standard',
                'hub' => $hubLbv, 'weight' => 4.2, 'vol_w' => 5.0,
                'l' => 45, 'w' => 35, 'h' => 20,
                'value' => 249300, 'currency' => 'XAF', 'price' => 44500,
                'payment_status' => 'paid', 'amount_paid' => 44500, 'paid_at' => now()->subDays(32),
                'items' => [
                    ['desc' => 'Robe de soirée Zara', 'qty' => 2, 'wt' => 1.0, 'val' => 118100, 'cat' => $catVet],
                    ['desc' => 'Sac à main Michael Kors', 'qty' => 1, 'wt' => 0.8, 'val' => 131200, 'cat' => $catVet],
                ],
                'logs' => [
                    ['status' => ShipmentStatus::PendingDropOff, 'title' => 'Expédition créée', 'days_ago' => 40],
                    ['status' => ShipmentStatus::ReceivedAtHub, 'title' => 'Réceptionné à Paris', 'days_ago' => 38],
                    ['status' => ShipmentStatus::InTransit, 'title' => 'En transit', 'days_ago' => 36],
                    ['status' => ShipmentStatus::ArrivedAtDestination, 'title' => 'Arrivé à Libreville', 'days_ago' => 32],
                    ['status' => ShipmentStatus::Delivered, 'title' => 'Livré', 'days_ago' => 30],
                ],
                'created_ago' => 40,
            ],
            // 8. Arrivé à destination — en attente livraison (USA → Gabon)
            [
                'tracking' => 'EXP2026050008', 'doc' => 'EXP6379850008',
                'sender' => $senderProfile1, 'recipient' => $recipPapa,
                'creator' => $this->operator, 'agency' => $this->libreville,
                'origin' => $this->countryUS, 'dest' => $this->countryGA,
                'status' => ShipmentStatus::ArrivedAtDestination, 'flow' => 'standard',
                'hub' => $hubLbv, 'weight' => 6.0, 'vol_w' => 8.0,
                'l' => 55, 'w' => 40, 'h' => 30,
                'value' => 312000, 'currency' => 'XAF', 'price' => 108000,
                'payment_status' => 'paid', 'amount_paid' => 108000, 'paid_at' => now()->subDays(15),
                'items' => [
                    ['desc' => 'Tablette Samsung Galaxy Tab S9', 'qty' => 1, 'wt' => 0.6, 'val' => 210000, 'cat' => $catElec],
                    ['desc' => 'Étui + stylet Samsung', 'qty' => 1, 'wt' => 0.3, 'val' => 42000, 'cat' => $catElec],
                    ['desc' => 'Casque Sony WH-1000XM5', 'qty' => 1, 'wt' => 0.3, 'val' => 60000, 'cat' => $catElec],
                ],
                'logs' => [
                    ['status' => ShipmentStatus::PendingDropOff, 'title' => 'Expédition créée', 'days_ago' => 18],
                    ['status' => ShipmentStatus::ReceivedAtHub, 'title' => 'Réceptionné à Miami', 'days_ago' => 16],
                    ['status' => ShipmentStatus::InTransit, 'title' => 'En transit US → GA', 'days_ago' => 14],
                    ['status' => ShipmentStatus::CustomsHold, 'title' => 'Dédouanement', 'days_ago' => 5],
                    ['status' => ShipmentStatus::ArrivedAtDestination, 'title' => 'Arrivé à Libreville', 'days_ago' => 3],
                ],
                'created_ago' => 18,
            ],
            // 9. Échec livraison — Dubaï → Port-Gentil
            [
                'tracking' => 'EXP2026050009', 'doc' => 'EXP6379850009',
                'sender' => $senderProfile2, 'recipient' => $recipTonton,
                'creator' => $this->operator, 'agency' => $this->portGentil ?? $this->libreville,
                'origin' => $this->countryAE, 'dest' => $this->countryGA,
                'status' => ShipmentStatus::DeliveryFailed, 'flow' => 'standard',
                'hub' => $hubPog, 'weight' => 2.8, 'vol_w' => 3.5,
                'l' => 40, 'w' => 30, 'h' => 20,
                'value' => 190200, 'currency' => 'XAF', 'price' => 27650,
                'payment_status' => 'paid', 'amount_paid' => 27650, 'paid_at' => now()->subDays(10),
                'items' => [
                    ['desc' => 'Équipement de sport', 'qty' => 3, 'wt' => 2.8, 'val' => 190200, 'cat' => $catSport],
                ],
                'logs' => [
                    ['status' => ShipmentStatus::PendingDropOff, 'title' => 'Expédition créée', 'days_ago' => 15],
                    ['status' => ShipmentStatus::ReceivedAtHub, 'title' => 'Réceptionné à Dubaï', 'days_ago' => 13],
                    ['status' => ShipmentStatus::InTransit, 'title' => 'En transit AE → GA', 'days_ago' => 11],
                    ['status' => ShipmentStatus::ArrivedAtDestination, 'title' => 'Arrivé à Port-Gentil', 'days_ago' => 5],
                    ['status' => ShipmentStatus::DeliveryFailed, 'title' => 'Destinataire absent', 'days_ago' => 3],
                ],
                'created_ago' => 15,
            ],
            // 10. Annulé
            [
                'tracking' => 'EXP2026050010', 'doc' => 'EXP6379850010',
                'sender' => $senderProfile1, 'recipient' => $recipPapa,
                'creator' => $this->operator, 'agency' => $this->libreville,
                'origin' => $this->countryFR, 'dest' => $this->countryGA,
                'status' => ShipmentStatus::Cancelled, 'flow' => 'standard',
                'hub' => null, 'weight' => 1.0, 'vol_w' => null,
                'l' => 25, 'w' => 20, 'h' => 10,
                'value' => 52500, 'currency' => 'XAF', 'price' => 8900,
                'payment_status' => 'unpaid', 'amount_paid' => 0, 'paid_at' => null,
                'items' => [
                    ['desc' => 'Livre annulé', 'qty' => 1, 'wt' => 1.0, 'val' => 52500, 'cat' => $catDoc],
                ],
                'logs' => [
                    ['status' => ShipmentStatus::PendingDropOff, 'title' => 'Expédition créée', 'days_ago' => 20],
                    ['status' => ShipmentStatus::Cancelled, 'title' => 'Annulé par le client', 'days_ago' => 18],
                ],
                'created_ago' => 20,
            ],
            // 11. Réceptionné au hub — client extra
            [
                'tracking' => 'EXP2026050011', 'doc' => 'EXP6379850011',
                'sender' => $this->extraClients['aurelie.mba@kotinda.local']?->profile, 'recipient' => $recipPapa,
                'creator' => $this->operator, 'agency' => $this->libreville,
                'origin' => $this->countryFR, 'dest' => $this->countryGA,
                'status' => ShipmentStatus::ReceivedAtHub, 'flow' => 'standard',
                'hub' => $hubPar, 'weight' => 7.5, 'vol_w' => 9.0,
                'l' => 50, 'w' => 40, 'h' => 30,
                'value' => 393600, 'currency' => 'XAF', 'price' => 80100,
                'payment_status' => 'unpaid', 'amount_paid' => 0, 'paid_at' => null,
                'items' => [
                    ['desc' => 'Mixeur Moulinex', 'qty' => 1, 'wt' => 4.5, 'val' => 229600, 'cat' => $catMaison],
                    ['desc' => 'Set de casseroles Tefal', 'qty' => 1, 'wt' => 3.0, 'val' => 164000, 'cat' => $catMaison],
                ],
                'logs' => [
                    ['status' => ShipmentStatus::PendingDropOff, 'title' => 'Expédition créée', 'days_ago' => 4],
                    ['status' => ShipmentStatus::ReceivedAtHub, 'title' => 'Réceptionné à Paris', 'days_ago' => 2],
                ],
                'created_ago' => 4,
            ],
            // 12. Problème signalé
            [
                'tracking' => 'EXP2026050012', 'doc' => 'EXP6379850012',
                'sender' => $this->extraClients['fabrice.nguema@kotinda.local']?->profile, 'recipient' => $recipMaman,
                'creator' => $this->operator, 'agency' => $this->libreville,
                'origin' => $this->countryFR, 'dest' => $this->countryGA,
                'status' => ShipmentStatus::IssueReported, 'flow' => 'standard',
                'hub' => $hubPar, 'weight' => 3.0, 'vol_w' => 3.5,
                'l' => 40, 'w' => 30, 'h' => 15,
                'value' => 164000, 'currency' => 'XAF', 'price' => 31150,
                'payment_status' => 'paid', 'amount_paid' => 31150, 'paid_at' => now()->subDays(8),
                'items' => [
                    ['desc' => 'Vase en cristal (fragile)', 'qty' => 1, 'wt' => 3.0, 'val' => 164000, 'cat' => $catMaison],
                ],
                'logs' => [
                    ['status' => ShipmentStatus::PendingDropOff, 'title' => 'Expédition créée', 'days_ago' => 10],
                    ['status' => ShipmentStatus::ReceivedAtHub, 'title' => 'Réceptionné à Paris', 'days_ago' => 8],
                    ['status' => ShipmentStatus::IssueReported, 'title' => 'Colis endommagé à la réception', 'days_ago' => 7],
                ],
                'created_ago' => 10,
            ],
        ];

        foreach ($shipmentData as $s) {
            $shipment = Shipment::updateOrCreate(
                ['public_tracking' => $s['tracking']],
                [
                    'invoice_document_number' => $s['doc'],
                    'sender_profile_id' => $s['sender']?->id,
                    'recipient_profile_id' => $s['recipient']?->id,
                    'creator_user_id' => $s['creator']->id,
                    'agency_id' => $s['agency']?->id,
                    'origin_country_id' => $s['origin'],
                    'dest_country_id' => $s['dest'],
                    'status' => $s['status'],
                    'service_flow' => $s['flow'],
                    'current_hub_id' => $s['hub']?->id,
                    'weight_kg' => $s['weight'],
                    'volumetric_weight_kg' => $s['vol_w'],
                    'length_cm' => $s['l'],
                    'width_cm' => $s['w'],
                    'height_cm' => $s['h'],
                    'declared_value' => $s['value'],
                    'declared_currency' => $s['currency'],
                    'calculated_price' => $s['price'],
                    'currency' => $s['currency'],
                    'payment_status' => $s['payment_status'],
                    'amount_paid' => $s['amount_paid'],
                    'paid_at' => $s['paid_at'],
                    'created_at' => now()->subDays($s['created_ago']),
                ]
            );

            $catDefault = $catElec;
            foreach ($s['items'] as $item) {
                ShipmentItem::updateOrCreate(
                    ['shipment_id' => $shipment->id, 'description' => $item['desc']],
                    ['quantity' => $item['qty'], 'weight_kg' => $item['wt'], 'value' => $item['val'], 'origin_country_id' => $s['origin'], 'category_id' => ($item['cat'] ?? $catDefault)?->id]
                );
            }

            foreach ($s['logs'] as $log) {
                ShipmentLog::updateOrCreate(
                    ['shipment_id' => $shipment->id, 'status' => $log['status'], 'title' => $log['title']],
                    ['user_id' => $s['creator']->id, 'created_at' => now()->subDays($log['days_ago'])]
                );
            }
        }
    }

    // ─── Regroupements ──────────────────────────────────────────────────

    private function seedRegroupements(): void
    {
        $this->command?->info('Regroupements…');

        $shipment1 = Shipment::where('public_tracking', 'EXP2026030001')->first();
        $shipment4 = Shipment::where('public_tracking', 'EXP2026050004')->first();
        $shipment11 = Shipment::where('public_tracking', 'EXP2026050011')->first();

        $regroup1 = Regroupement::updateOrCreate(
            ['batch_number' => 'GRP-2026-001'],
            ['agency_id' => $this->paris?->id, 'status' => ShipmentStatus::Delivered]
        );

        $regroup2 = Regroupement::updateOrCreate(
            ['batch_number' => 'GRP-2026-002'],
            ['agency_id' => $this->paris?->id, 'status' => ShipmentStatus::ReadyForDispatch]
        );

        if ($shipment1) {
            $shipment1->update(['regroupement_id' => $regroup1->id]);
        }
        if ($shipment4) {
            $shipment4->update(['regroupement_id' => $regroup2->id]);
        }
        if ($shipment11) {
            $shipment11->update(['regroupement_id' => $regroup2->id]);
        }
    }

    // ─── Pickups / Ramassages ───────────────────────────────────────────

    private function seedPickups(): void
    {
        $this->command?->info('Ramassages…');

        $shipment5 = Shipment::where('public_tracking', 'EXP2026050005')->first();
        $shipment8 = Shipment::where('public_tracking', 'EXP2026050008')->first();

        $pickups = [
            ['user_id' => $this->client1->id, 'agency_id' => $this->libreville->id, 'shipment_id' => $shipment8?->id, 'status' => PickupStatus::Completed, 'assigned_driver_id' => $this->driver->id, 'latitude' => 0.4060, 'longitude' => 9.4370, 'address_text' => 'Rue Pierre Barro, quartier Louis, Libreville', 'requested_window' => 'Matin 8h-12h', 'completed_at' => now()->subDays(3), 'completion_notes' => 'Livré avec succès au domicile (Louis).'],
            ['user_id' => $this->client2->id, 'agency_id' => $this->libreville->id, 'shipment_id' => null, 'status' => PickupStatus::DriverAssigned, 'assigned_driver_id' => $this->driver->id, 'latitude' => 0.5160, 'longitude' => 9.4110, 'address_text' => 'Angondjé, Akanda', 'requested_window' => 'Après-midi 14h-18h'],
            ['user_id' => $this->client1->id, 'agency_id' => $this->libreville->id, 'shipment_id' => $shipment5?->id, 'status' => PickupStatus::Draft, 'assigned_driver_id' => null, 'latitude' => 0.3850, 'longitude' => 9.4480, 'address_text' => 'Boulevard de l\'Indépendance, Glass, Libreville', 'requested_window' => 'Demain matin'],
            ['user_id' => $this->extraClients['nadege.mouele@kotinda.local']?->id, 'agency_id' => $this->libreville->id, 'shipment_id' => null, 'status' => PickupStatus::Failed, 'assigned_driver_id' => $this->driver->id, 'latitude' => 0.4170, 'longitude' => 9.4890, 'address_text' => 'Carrefour Nzeng-Ayong, Libreville', 'requested_window' => 'Hier matin', 'failure_reason' => 'Client absent, téléphone injoignable'],
            ['user_id' => $this->extraClients['yannick.bongo@kotinda.local']?->id, 'agency_id' => $this->libreville->id, 'shipment_id' => null, 'status' => PickupStatus::EnRoute, 'assigned_driver_id' => $this->driver->id, 'latitude' => 0.3020, 'longitude' => 9.5010, 'address_text' => 'Owendo, cité SNI', 'requested_window' => 'Maintenant'],
        ];

        foreach ($pickups as $data) {
            if ($data['user_id']) {
                Pickup::create($data);
            }
        }
    }

    // ─── Factures + Paiements expéditions ───────────────────────────────

    private function seedInvoicesAndPayments(): void
    {
        $this->command?->info('Factures et paiements…');

        $paidShipments = Shipment::whereIn('payment_status', ['paid', 'partial'])->get();

        $invNum = 1;
        foreach ($paidShipments as $shipment) {
            $user = $shipment->creator ?? $this->client1;
            $agency = $shipment->agency ?? $this->libreville;

            $invoice = Invoice::updateOrCreate(
                ['shipment_id' => $shipment->id],
                [
                    'invoice_number' => 'INV-2026-'.str_pad($invNum, 5, '0', STR_PAD_LEFT),
                    'user_id' => $user->id,
                    'agency_id' => $agency->id,
                    'amount' => $shipment->calculated_price,
                    'base_amount' => $shipment->calculated_price,
                    'currency' => $shipment->currency ?? 'XAF',
                    'status' => $shipment->payment_status === 'paid' ? 'paid' : 'partial',
                    'due_at' => $shipment->created_at->addDays(15),
                    'paid_at' => $shipment->paid_at,
                ]
            );

            ShipmentPayment::updateOrCreate(
                ['shipment_id' => $shipment->id, 'amount' => $shipment->amount_paid],
                [
                    'payment_method' => collect(['Airtel Money', 'Moov Money', 'Virement SEPA', 'Espèces FCFA', 'Carte bancaire'])->random(),
                    'reference' => 'PAY-'.strtoupper(Str::random(8)),
                    'notes' => 'Paiement reçu',
                    'recorded_by_user_id' => $this->operator->id,
                ]
            );

            $invNum++;
        }

        $extraInvoices = [
            ['inv' => 'INV-2026-00020', 'user' => $this->client1, 'amount' => 45000, 'status' => 'pending', 'days_ago' => 5],
            ['inv' => 'INV-2026-00021', 'user' => $this->client2, 'amount' => 68500, 'status' => 'overdue', 'days_ago' => 30],
            ['inv' => 'INV-2026-00022', 'user' => $this->client3, 'amount' => 75250, 'status' => 'pending', 'days_ago' => 2],
        ];

        foreach ($extraInvoices as $ei) {
            Invoice::updateOrCreate(
                ['invoice_number' => $ei['inv']],
                [
                    'user_id' => $ei['user']->id,
                    'agency_id' => $ei['user']->agency_id ?? $this->libreville->id,
                    'amount' => $ei['amount'],
                    'base_amount' => $ei['amount'],
                    'currency' => 'XAF',
                    'status' => $ei['status'],
                    'due_at' => now()->subDays($ei['days_ago'])->addDays(15),
                    'created_at' => now()->subDays($ei['days_ago']),
                ]
            );
        }
    }

    // ─── Écritures comptables ───────────────────────────────────────────

    private function seedLedgerEntries(): void
    {
        $this->command?->info('Écritures comptables…');

        $invoices = Invoice::where('status', 'paid')->get();

        foreach ($invoices as $invoice) {
            LedgerEntry::updateOrCreate(
                ['invoice_id' => $invoice->id, 'type' => 'credit'],
                [
                    'agency_id' => $invoice->agency_id,
                    'user_id' => $invoice->user_id,
                    'amount' => $invoice->amount,
                    'currency' => $invoice->currency,
                    'description' => "Paiement facture {$invoice->invoice_number}",
                ]
            );
        }

        $extraEntries = [
            ['type' => 'debit', 'amount' => 150000, 'desc' => 'Achat carburant véhicules de livraison', 'days_ago' => 10],
            ['type' => 'debit', 'amount' => 900000, 'desc' => 'Loyer entrepôt Owendo – Mai 2026', 'days_ago' => 3],
            ['type' => 'debit', 'amount' => 480000, 'desc' => 'Salaires livreurs – Avril 2026', 'days_ago' => 15],
            ['type' => 'credit', 'amount' => 210000, 'desc' => 'Commission assistance achat – Lot Mars', 'days_ago' => 40],
            ['type' => 'debit', 'amount' => 72000, 'desc' => 'Fournitures bureau (emballages)', 'days_ago' => 7],
        ];

        foreach ($extraEntries as $entry) {
            LedgerEntry::create([
                'agency_id' => $this->libreville->id,
                'user_id' => $this->adminLbv->id,
                'amount' => $entry['amount'],
                'currency' => 'XAF',
                'type' => $entry['type'],
                'description' => $entry['desc'],
                'created_at' => now()->subDays($entry['days_ago']),
            ]);
        }
    }

    // ─── Réponses rapides SAV ───────────────────────────────────────────

    private function seedSavQuickReplies(): void
    {
        $this->command?->info('Réponses rapides SAV…');

        $replies = [
            ['title' => 'Accusé de réception', 'body' => "Bonjour,\n\nNous avons bien reçu votre réclamation et l'avons enregistrée sous la référence {{ticket_ref}}. Un membre de notre équipe vous recontactera sous 24h.\n\nCordialement,\nÉquipe SAV Kotinda", 'category' => 'general', 'sort_order' => 1],
            ['title' => 'Demande de preuve', 'body' => "Bonjour,\n\nPour traiter votre réclamation, nous avons besoin de photos du colis et de son contenu. Merci de les joindre à ce ticket.\n\nCordialement,\nÉquipe SAV Kotinda", 'category' => 'lost_damaged', 'sort_order' => 2],
            ['title' => 'Retard — mise à jour', 'body' => "Bonjour,\n\nVotre colis est actuellement en transit et subit un retard dû à {{raison}}. Nous estimons la livraison sous {{delai}} jours ouvrés.\n\nNous vous prions de nous excuser pour ce désagrément.\n\nCordialement,\nÉquipe SAV Kotinda", 'category' => 'delivery_delay', 'sort_order' => 3],
            ['title' => 'Remboursement initié', 'body' => "Bonjour,\n\nSuite à votre demande, nous avons initié un remboursement de {{montant}} {{devise}}. Le traitement prend 5-10 jours ouvrés selon votre mode de paiement.\n\nCordialement,\nÉquipe SAV Kotinda", 'category' => 'refund', 'sort_order' => 4],
            ['title' => 'Ticket résolu', 'body' => "Bonjour,\n\nVotre réclamation a été traitée avec succès. Si vous avez d'autres questions, n'hésitez pas à nous contacter.\n\nMerci de votre confiance.\n\nCordialement,\nÉquipe SAV Kotinda", 'category' => 'general', 'sort_order' => 5],
            ['title' => 'En attente info client', 'body' => "Bonjour,\n\nNous avons besoin d'informations complémentaires pour traiter votre demande. Pourriez-vous nous fournir {{info_requise}} ?\n\nMerci d'avance.\n\nCordialement,\nÉquipe SAV Kotinda", 'category' => 'general', 'sort_order' => 6],
        ];

        foreach ($replies as $r) {
            SavQuickReply::updateOrCreate(
                ['agency_id' => $this->libreville->id, 'title' => $r['title']],
                array_merge($r, ['agency_id' => $this->libreville->id, 'is_active' => true])
            );
        }
    }

    // ─── Tickets SAV + Messages ─────────────────────────────────────────

    private function seedSavTickets(): void
    {
        $this->command?->info('Tickets SAV et messages…');

        $shipment9 = Shipment::where('public_tracking', 'EXP2026050009')->first();
        $shipment12 = Shipment::where('public_tracking', 'EXP2026050012')->first();
        $shipment3 = Shipment::where('public_tracking', 'EXP2026040003')->first();

        $fabrice = $this->extraClients['fabrice.nguema@kotinda.local'] ?? null;
        $nadege = $this->extraClients['nadege.mouele@kotinda.local'] ?? null;

        $tickets = [
            [
                'ref' => 'TKT-2026-0001', 'agency' => $this->portGentil ?? $this->libreville, 'client' => $this->client2, 'assigned' => $this->operator, 'created_by' => $this->client2,
                'category' => SavTicketCategory::DeliveryDelay, 'priority' => SavTicketPriority::Normal, 'status' => SavTicketStatus::InProgress,
                'channel' => 'web', 'subject' => 'Mon colis est en retard de 2 semaines',
                'description' => "Bonjour, mon colis EXP2026050009 devait arriver à Port-Gentil il y a 2 semaines mais il n'est toujours pas livré. Le suivi indique « échec de livraison ». Quand mon oncle va-t-il le recevoir ?",
                'related_type' => Shipment::class, 'related_id' => $shipment9?->id,
                'sla_deadline_at' => now()->addHours(48), 'first_response_at' => now()->subHours(2), 'days_ago' => 3,
                'messages' => [
                    ['user' => $this->client2, 'body' => "Bonjour, j'attends le colis EXP2026050009 depuis 2 semaines. Le livreur n'est jamais passé chez mon oncle à Grand Village. Merci de me donner une mise à jour.", 'internal' => false, 'days_ago' => 3],
                    ['user' => $this->operator, 'body' => "Bonjour Ghislain, nous avons vérifié votre dossier. Le livreur s'est présenté mais personne n'était disponible à l'adresse indiquée. Nous reprogrammons la livraison pour demain matin.", 'internal' => false, 'days_ago' => 2],
                    ['user' => $this->operator, 'body' => 'Note interne : contacter l\'agence de Port-Gentil pour confirmer la reprogrammation.', 'internal' => true, 'days_ago' => 2],
                ],
            ],
            [
                'ref' => 'TKT-2026-0002', 'agency' => $this->libreville, 'client' => $fabrice, 'assigned' => $this->operator, 'created_by' => $this->operator,
                'category' => SavTicketCategory::LostDamaged, 'priority' => SavTicketPriority::Urgent, 'status' => SavTicketStatus::Escalated,
                'channel' => 'phone', 'subject' => 'Colis endommagé — vase en cristal cassé',
                'description' => "Le client appelle : le colis EXP2026050012 contenant un vase en cristal est arrivé endommagé. Le vase est cassé en morceaux. Photos envoyées par WhatsApp.",
                'related_type' => Shipment::class, 'related_id' => $shipment12?->id,
                'sla_deadline_at' => now()->addHours(12), 'first_response_at' => now()->subHours(5), 'escalated_at' => now()->subHours(1), 'days_ago' => 2,
                'messages' => [
                    ['user' => $this->operator, 'body' => "Le client Fabrice Nguema a appelé pour signaler un colis endommagé. Photos reçues via WhatsApp montrant le vase cassé.", 'internal' => false, 'days_ago' => 2],
                    ['user' => $this->operator, 'body' => "Note interne : vérifier l'assurance transport. Valeur déclarée 164 000 FCFA. Escalader à l'admin pour décision de remboursement.", 'internal' => true, 'days_ago' => 1],
                    ['user' => $this->adminLbv, 'body' => "Dossier pris en charge. Je contacte le hub de Paris pour le rapport de dommage. Remboursement partiel à envisager.", 'internal' => true, 'days_ago' => 0],
                ],
            ],
            [
                'ref' => 'TKT-2026-0003', 'agency' => $this->libreville, 'client' => $this->client1, 'assigned' => $this->operator, 'created_by' => $this->client1,
                'category' => SavTicketCategory::CustomsIssue, 'priority' => SavTicketPriority::Normal, 'status' => SavTicketStatus::WaitingClient,
                'channel' => 'web', 'subject' => 'Colis bloqué en douane depuis 5 jours',
                'description' => "Mon colis EXP2026040003 est bloqué en douane au port d'Owendo depuis 5 jours. Pouvez-vous m'aider à débloquer la situation ?",
                'related_type' => Shipment::class, 'related_id' => $shipment3?->id,
                'sla_deadline_at' => now()->addHours(72), 'first_response_at' => now()->subDays(2), 'days_ago' => 5,
                'messages' => [
                    ['user' => $this->client1, 'body' => "Bonjour, mon colis maritime est bloqué en douane depuis 5 jours. Numéro EXP2026040003. C'est un groupe électrogène.", 'internal' => false, 'days_ago' => 5],
                    ['user' => $this->operator, 'body' => "Bonjour Christelle, votre colis nécessite un document supplémentaire pour le dédouanement : la facture originale du fournisseur chinois. Pouvez-vous nous la transmettre ?", 'internal' => false, 'days_ago' => 3],
                ],
            ],
            [
                'ref' => 'TKT-2026-0004', 'agency' => $this->libreville, 'client' => $this->client1, 'assigned' => null, 'created_by' => $this->client1,
                'category' => SavTicketCategory::GeneralQuestion, 'priority' => SavTicketPriority::Low, 'status' => SavTicketStatus::Resolved,
                'channel' => 'web', 'subject' => 'Comment fonctionne le regroupement de colis ?',
                'description' => "Je voudrais comprendre comment fonctionne le service de regroupement. Est-ce que je peux regrouper des colis venant de différents magasins ?",
                'sla_deadline_at' => now()->subDays(5), 'first_response_at' => now()->subDays(8), 'resolved_at' => now()->subDays(6), 'days_ago' => 10,
                'messages' => [
                    ['user' => $this->client1, 'body' => "Bonjour, je voudrais savoir si je peux regrouper des colis de plusieurs magasins différents en un seul envoi vers Libreville ?", 'internal' => false, 'days_ago' => 10],
                    ['user' => $this->operator, 'body' => "Bonjour Christelle ! Oui, c'est exactement le principe du regroupement. Vous pouvez faire livrer plusieurs colis à votre casier KTD-LBV-0001 dans notre entrepôt de Paris. Une fois tous les colis arrivés, nous les regroupons en un seul envoi, ce qui réduit considérablement les frais d'expédition. Vous bénéficiez de 45 jours de stockage gratuit.", 'internal' => false, 'days_ago' => 8],
                    ['user' => $this->client1, 'body' => "Super, merci beaucoup pour l'explication ! C'est très clair.", 'internal' => false, 'days_ago' => 7],
                ],
            ],
            [
                'ref' => 'TKT-2026-0005', 'agency' => $this->libreville, 'client' => $nadege, 'assigned' => $this->operator, 'created_by' => $nadege,
                'category' => SavTicketCategory::PaymentIssue, 'priority' => SavTicketPriority::Urgent, 'status' => SavTicketStatus::Open,
                'channel' => 'whatsapp', 'subject' => 'Paiement Airtel Money non pris en compte',
                'description' => "J'ai payé 68 500 FCFA via Airtel Money il y a 3 jours mais ma facture indique toujours « impayé ». Voici le code de confirmation : AM20260509123456.",
                'sla_deadline_at' => now()->addHours(24), 'days_ago' => 1,
                'messages' => [
                    ['user' => $nadege, 'body' => "Bonjour, j'ai payé 68 500 FCFA via Airtel Money le 09/05/2026. Code de confirmation : AM20260509123456. Mon compte indique toujours impayé. Merci de vérifier.", 'internal' => false, 'days_ago' => 1],
                ],
            ],
            [
                'ref' => 'TKT-2026-0006', 'agency' => $this->libreville, 'client' => $this->client2, 'assigned' => $this->operator, 'created_by' => $this->client2,
                'category' => SavTicketCategory::NonConforming, 'priority' => SavTicketPriority::Normal, 'status' => SavTicketStatus::Closed,
                'channel' => 'email', 'subject' => 'Article reçu ne correspond pas à la commande',
                'description' => "J'ai commandé des coques Samsung S24 mais j'ai reçu des coques pour iPhone 15. Ce n'est pas ce que j'ai commandé.",
                'sla_deadline_at' => now()->subDays(15), 'first_response_at' => now()->subDays(18), 'resolved_at' => now()->subDays(12), 'closed_at' => now()->subDays(10), 'days_ago' => 20,
                'messages' => [
                    ['user' => $this->client2, 'body' => "J'ai reçu les mauvais articles. Je voulais des coques Samsung S24 Ultra et j'ai reçu des coques iPhone 15.", 'internal' => false, 'days_ago' => 20],
                    ['user' => $this->operator, 'body' => "Nous nous excusons pour cette erreur. Nous contactons le fournisseur AliExpress pour un échange. Vous recevrez les bons articles sous 2-3 semaines.", 'internal' => false, 'days_ago' => 18],
                    ['user' => $this->operator, 'body' => "Les articles corrects ont été renvoyés par AliExpress. Tracking : LY987654321CN.", 'internal' => false, 'days_ago' => 12],
                    ['user' => $this->client2, 'body' => "J'ai bien reçu les bons articles. Merci !", 'internal' => false, 'days_ago' => 10],
                ],
            ],
        ];

        foreach ($tickets as $t) {
            $ticket = SavTicket::updateOrCreate(
                ['reference_code' => $t['ref']],
                [
                    'agency_id' => $t['agency']->id,
                    'client_id' => $t['client']?->id,
                    'assigned_to' => $t['assigned']?->id,
                    'created_by' => $t['created_by']?->id,
                    'category' => $t['category'],
                    'priority' => $t['priority'],
                    'status' => $t['status'],
                    'channel' => $t['channel'],
                    'subject' => $t['subject'],
                    'description' => $t['description'],
                    'related_type' => $t['related_type'] ?? null,
                    'related_id' => $t['related_id'] ?? null,
                    'sla_deadline_at' => $t['sla_deadline_at'] ?? null,
                    'first_response_at' => $t['first_response_at'] ?? null,
                    'resolved_at' => $t['resolved_at'] ?? null,
                    'closed_at' => $t['closed_at'] ?? null,
                    'escalated_at' => $t['escalated_at'] ?? null,
                    'created_at' => now()->subDays($t['days_ago']),
                ]
            );

            foreach ($t['messages'] ?? [] as $msg) {
                SavTicketMessage::create([
                    'ticket_id' => $ticket->id,
                    'user_id' => $msg['user']?->id,
                    'body' => $msg['body'],
                    'is_internal' => $msg['internal'],
                    'channel' => $t['channel'],
                    'created_at' => now()->subDays($msg['days_ago']),
                ]);
            }
        }
    }

    // ─── Remboursements ─────────────────────────────────────────────────

    private function seedRefunds(): void
    {
        $this->command?->info('Remboursements…');

        $shipment12 = Shipment::where('public_tracking', 'EXP2026050012')->first();
        $murielle = $this->extraClients['murielle.ndong@kotinda.local'] ?? $this->client3;

        $refunds = [
            [
                'reference_code' => 'RMB-0001',
                'refundable_type' => Shipment::class, 'refundable_id' => $shipment12?->id,
                'client_id' => $this->extraClients['fabrice.nguema@kotinda.local']?->id,
                'agency_id' => $this->libreville->id,
                'amount' => 82000, 'currency' => 'XAF',
                'status' => RefundStatus::UnderReview,
                'reason' => 'Vase en cristal cassé à la réception. L\'assurance couvre 50 % de la valeur déclarée (164 000 FCFA).',
                'reason_category' => 'damaged',
                'payment_method' => 'Airtel Money',
                'reviewed_by' => $this->adminLbv->id,
                'reviewed_at' => now()->subHours(3),
            ],
            [
                'reference_code' => 'RMB-0002',
                'refundable_type' => AssistedPurchase::class,
                'refundable_id' => AssistedPurchase::where('status', AssistedPurchaseStatus::CANCELLED)->first()?->id,
                'client_id' => $this->client2->id,
                'agency_id' => $this->libreville->id,
                'amount' => 0, 'currency' => 'XAF',
                'status' => RefundStatus::Rejected,
                'reason' => 'Demande de remboursement pour achat assisté annulé.',
                'reason_category' => 'cancellation',
                'rejection_reason' => 'Aucun paiement effectué avant l\'annulation.',
                'reviewed_by' => $this->adminLbv->id,
                'reviewed_at' => now()->subDays(5),
            ],
            [
                'reference_code' => 'RMB-0003',
                'refundable_type' => Shipment::class,
                'refundable_id' => Shipment::where('public_tracking', 'EXP2026030001')->first()?->id,
                'client_id' => $murielle->id,
                'agency_id' => $this->paris?->id,
                'amount' => 8900, 'currency' => 'XAF',
                'status' => RefundStatus::Completed,
                'reason' => 'Surfacturation corrigée — erreur de pesée au hub (1 kg à 8 900 FCFA).',
                'reason_category' => 'overcharge',
                'payment_method' => 'Virement SEPA',
                'reviewed_by' => $this->adminPar->id, 'reviewed_at' => now()->subDays(50),
                'processed_by' => $this->adminPar->id, 'processed_at' => now()->subDays(48),
                'completed_at' => now()->subDays(45),
            ],
        ];

        foreach ($refunds as $data) {
            Refund::updateOrCreate(
                ['reference_code' => $data['reference_code']],
                $data
            );
        }
    }

    // ─── Notifications ──────────────────────────────────────────────────

    private function seedNotifications(): void
    {
        $this->command?->info('Notifications…');

        $notifs = [
            ['user' => $this->client1, 'type' => 'in_app', 'title' => 'Colis arrivé à destination', 'body' => 'Votre colis EXP2026050008 est arrivé à Libreville. Planifiez votre livraison.', 'action_url' => '/shipments/EXP2026050008', 'status' => 'sent', 'days_ago' => 3, 'read' => false],
            ['user' => $this->client1, 'type' => 'in_app', 'title' => 'Devis prêt — Nike Air Max 90', 'body' => 'Votre devis d\'achat assisté est prêt. Montant : 68 500 FCFA.', 'action_url' => '/purchase-orders', 'status' => 'sent', 'days_ago' => 2, 'read' => true],
            ['user' => $this->client1, 'type' => 'in_app', 'title' => 'Colis livré', 'body' => 'Votre colis EXP2026040007 a été livré avec succès. Merci d\'avoir choisi Kotinda !', 'action_url' => '/shipments/EXP2026040007', 'status' => 'sent', 'days_ago' => 30, 'read' => true],
            ['user' => $this->client2, 'type' => 'in_app', 'title' => 'Échec de livraison', 'body' => 'La livraison de votre colis EXP2026050009 à Port-Gentil a échoué. Destinataire absent. Nous reprogrammons.', 'action_url' => '/shipments/EXP2026050009', 'status' => 'sent', 'days_ago' => 3, 'read' => false],
            ['user' => $this->client2, 'type' => 'in_app', 'title' => 'Ticket SAV mis à jour', 'body' => 'Votre ticket TKT-2026-0001 a été mis à jour par l\'opérateur.', 'action_url' => '/sav/TKT-2026-0001', 'status' => 'sent', 'days_ago' => 2, 'read' => false],
            ['user' => $this->client3, 'type' => 'in_app', 'title' => 'Colis réceptionné', 'body' => 'Votre colis a été réceptionné dans notre entrepôt de Paris.', 'action_url' => '/shipments', 'status' => 'sent', 'days_ago' => 1, 'read' => false],
            ['user' => $this->operator, 'type' => 'in_app', 'title' => 'Nouveau ticket SAV urgent', 'body' => 'TKT-2026-0005 — Paiement Airtel Money non pris en compte. Priorité urgente.', 'action_url' => '/sav/TKT-2026-0005', 'status' => 'sent', 'days_ago' => 1, 'read' => false],
            ['user' => $this->driver, 'type' => 'in_app', 'title' => 'Nouveau ramassage assigné', 'body' => 'Un ramassage vous a été assigné : Angondjé, Akanda.', 'action_url' => '/pickups', 'status' => 'sent', 'days_ago' => 0, 'read' => false],
            ['user' => $this->adminLbv, 'type' => 'in_app', 'title' => 'Ticket escaladé', 'body' => 'Le ticket TKT-2026-0002 (colis endommagé) a été escaladé pour décision.', 'action_url' => '/sav/TKT-2026-0002', 'status' => 'sent', 'days_ago' => 0, 'read' => false],
            ['user' => $this->extraClients['aurelie.mba@kotinda.local'] ?? null, 'type' => 'in_app', 'title' => 'Pré-alerte confirmée', 'body' => 'Votre pré-alerte ASN-0007 a été enregistrée. Nous attendons votre colis à Paris.', 'action_url' => '/pre-alerts', 'status' => 'sent', 'days_ago' => 3, 'read' => true],
        ];

        foreach ($notifs as $n) {
            if (! $n['user']) {
                continue;
            }
            Notification::create([
                'user_id' => $n['user']->id,
                'type' => $n['type'],
                'channel' => 'database',
                'title' => $n['title'],
                'body' => $n['body'],
                'action_url' => $n['action_url'],
                'status' => $n['status'],
                'sent_at' => now()->subDays($n['days_ago']),
                'read_at' => $n['read'] ? now()->subDays($n['days_ago'])->addHours(2) : null,
                'created_at' => now()->subDays($n['days_ago']),
            ]);
        }
    }

    // ─── Abonnés newsletter ─────────────────────────────────────────────

    private function seedNewsletter(): void
    {
        $this->command?->info('Abonnés newsletter…');

        $subscribers = [
            ['email' => 'christelle.nze@kotinda.local', 'name' => 'Christelle Nzé', 'locale' => 'fr', 'source' => 'registration'],
            ['email' => 'ghislain.ondo@kotinda.local', 'name' => 'Ghislain Ondo', 'locale' => 'fr', 'source' => 'registration'],
            ['email' => 'prisca.koumba@kotinda.local', 'name' => 'Prisca Koumba', 'locale' => 'fr', 'source' => 'registration'],
            ['email' => 'aurelie.mba@kotinda.local', 'name' => 'Aurélie Mba', 'locale' => 'fr', 'source' => 'registration'],
            ['email' => 'nathalie.ella@gmail.com', 'name' => 'Nathalie Mba Ella', 'locale' => 'fr', 'source' => 'website'],
            ['email' => 'pacome.ndong@yahoo.fr', 'name' => 'Pacôme Ndong', 'locale' => 'fr', 'source' => 'website'],
            ['email' => 'julien.ovono@outlook.com', 'name' => 'Julien Ovono', 'locale' => 'fr', 'source' => 'website'],
            ['email' => 'sandrine.bekale@gmail.com', 'name' => 'Sandrine Bekale', 'locale' => 'fr', 'source' => 'facebook'],
            ['email' => 'alain.nzoghe@gmail.com', 'name' => 'Alain Nzoghe', 'locale' => 'fr', 'source' => 'website'],
            ['email' => 'viviane.ngoma@gmail.com', 'name' => 'Viviane Ngoma', 'locale' => 'fr', 'source' => 'instagram'],
            ['email' => 'pascal.mouity@gmail.com', 'name' => 'Pascal Mouity', 'locale' => 'en', 'source' => 'website'],
            ['email' => 'fatou.diallo@gmail.com', 'name' => 'Fatou Diallo', 'locale' => 'fr', 'source' => 'website'],
            ['email' => 'roger.mboumba@gmail.com', 'name' => 'Roger Mboumba', 'locale' => 'fr', 'source' => 'referral'],
            ['email' => 'clemence.mbadinga@gmail.com', 'name' => 'Clémence Mbadinga', 'locale' => 'fr', 'source' => 'website'],
            ['email' => 'jean.obame@gmail.com', 'name' => 'Jean Obame', 'locale' => 'fr', 'source' => 'website'],
        ];

        foreach ($subscribers as $i => $s) {
            NewsletterSubscriber::updateOrCreate(
                ['email' => $s['email']],
                array_merge($s, [
                    'is_active' => $i < 13,
                    'subscribed_at' => now()->subDays(rand(5, 120)),
                    'unsubscribed_at' => $i >= 13 ? now()->subDays(rand(1, 10)) : null,
                ])
            );
        }
    }

    // ─── Commentaires ───────────────────────────────────────────────────

    private function seedComments(): void
    {
        $this->command?->info('Commentaires…');

        $shipment3 = Shipment::where('public_tracking', 'EXP2026040003')->first();
        $shipment9 = Shipment::where('public_tracking', 'EXP2026050009')->first();
        $ap1 = AssistedPurchase::where('status', AssistedPurchaseStatus::ARRIVED_AT_HUB)->first();

        $comments = [
            ['type' => Shipment::class, 'id' => $shipment3?->id, 'user' => $this->customs, 'body' => 'Documents douaniers reçus au port d\'Owendo — manque le certificat d\'origine. Contacter l\'expéditeur.', 'internal' => true, 'days_ago' => 4],
            ['type' => Shipment::class, 'id' => $shipment3?->id, 'user' => $this->operator, 'body' => 'Certificat d\'origine demandé au fournisseur chinois. Délai estimé 48h.', 'internal' => true, 'days_ago' => 3],
            ['type' => Shipment::class, 'id' => $shipment9?->id, 'user' => $this->driver, 'body' => 'Première tentative de livraison échouée — personne au domicile. Reprogrammé pour demain.', 'internal' => false, 'days_ago' => 3],
            ['type' => AssistedPurchase::class, 'id' => $ap1?->id, 'user' => $this->operator, 'body' => 'Trottinette reçue au hub de Paris. Poids vérifié : 14,5 kg (estimé 15 kg). Légère différence acceptable.', 'internal' => false, 'days_ago' => 1],
            ['type' => AssistedPurchase::class, 'id' => $ap1?->id, 'user' => $this->operator, 'body' => 'Photos de réception prises et ajoutées au dossier. Article en bon état.', 'internal' => true, 'days_ago' => 1],
        ];

        foreach ($comments as $c) {
            if ($c['id']) {
                Comment::create([
                    'commentable_type' => $c['type'],
                    'commentable_id' => $c['id'],
                    'user_id' => $c['user']->id,
                    'body' => $c['body'],
                    'is_internal' => $c['internal'],
                    'created_at' => now()->subDays($c['days_ago']),
                ]);
            }
        }
    }
}
