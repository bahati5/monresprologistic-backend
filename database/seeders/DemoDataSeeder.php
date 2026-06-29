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
 * Données transactionnelles massives pour la démo vidéo Monrespro Logistics.
 *
 * Pré-requis : DatabaseSeeder (FullPlatformSeeder + RbacSeeder) déjà exécuté.
 *
 * Couvre : clients supplémentaires, profils destinataires, carnets d'adresses,
 * pré-alertes, colis clients, expéditions (tous statuts), items, logs,
 * regroupements, pickups, factures, paiements, écritures comptables,
 * tickets SAV + messages, réponses rapides SAV, remboursements,
 * notifications, abonnés newsletter, commentaires.
 *
 * Usage : php artisan db:seed --class=DemoDataSeeder
 */
class DemoDataSeeder extends Seeder
{
    private Agency $kinshasa;
    private ?Agency $bruxelles;
    private ?Agency $paris;
    private ?Agency $guangzhou;

    private User $superAdmin;
    private User $adminKin;
    private User $adminBxl;
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

    private ?int $countryCD;
    private ?int $countryBE;
    private ?int $countryFR;
    private ?int $countryCN;
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
        $this->kinshasa = Agency::where('code', 'KIN')->firstOrFail();
        $this->bruxelles = Agency::where('code', 'BXL')->first();
        $this->paris = Agency::where('code', 'PAR')->first();
        $this->guangzhou = Agency::where('code', 'GZ')->first();

        $this->superAdmin = User::where('email', 'admin@monrespro.local')->firstOrFail();
        $this->adminKin = User::where('email', 'admin.kin@monrespro.local')->firstOrFail();
        $this->adminBxl = User::where('email', 'admin.bxl@monrespro.local')->firstOrFail();
        $this->operator = User::where('email', 'operateur@monrespro.local')->firstOrFail();
        $this->driver = User::where('email', 'livreur@monrespro.local')->firstOrFail();
        $this->customs = User::where('email', 'douane@monrespro.local')->firstOrFail();
        $this->client1 = User::where('email', 'client1@monrespro.local')->firstOrFail();
        $this->client2 = User::where('email', 'client2@monrespro.local')->firstOrFail();
        $this->client3 = User::where('email', 'client3@monrespro.local')->firstOrFail();

        $this->countryCD = DB::table('countries')->where('iso2', 'CD')->value('id');
        $this->countryBE = DB::table('countries')->where('iso2', 'BE')->value('id');
        $this->countryFR = DB::table('countries')->where('iso2', 'FR')->value('id');
        $this->countryCN = DB::table('countries')->where('iso2', 'CN')->value('id');
        $this->countryUS = DB::table('countries')->where('iso2', 'US')->value('id');
    }

    // ─── Clients supplémentaires (pour volume analytics) ────────────────

    private function seedExtraClients(): void
    {
        $this->command?->info('Clients supplémentaires…');

        $clients = [
            ['first' => 'Marie', 'last' => 'Lukusa', 'email' => 'marie.lukusa@monrespro.local', 'phone' => '+243 900 600 001', 'agency' => $this->kinshasa, 'locker' => 'MRP-KIN-0003'],
            ['first' => 'Samuel', 'last' => 'Kibangula', 'email' => 'samuel.kib@monrespro.local', 'phone' => '+243 900 600 002', 'agency' => $this->kinshasa, 'locker' => 'MRP-KIN-0004'],
            ['first' => 'Rachel', 'last' => 'Mbuyi', 'email' => 'rachel.mbuyi@monrespro.local', 'phone' => '+243 900 600 003', 'agency' => $this->kinshasa, 'locker' => 'MRP-KIN-0005'],
            ['first' => 'Christian', 'last' => 'Nseka', 'email' => 'christian.nseka@monrespro.local', 'phone' => '+243 900 600 004', 'agency' => $this->kinshasa, 'locker' => 'MRP-KIN-0006'],
            ['first' => 'Esther', 'last' => 'Tshilanda', 'email' => 'esther.tshi@monrespro.local', 'phone' => '+243 900 600 005', 'agency' => $this->kinshasa, 'locker' => 'MRP-KIN-0007'],
            ['first' => 'Jean-Pierre', 'last' => 'Bakajika', 'email' => 'jp.bakajika@monrespro.local', 'phone' => '+243 900 600 006', 'agency' => $this->kinshasa, 'locker' => 'MRP-KIN-0008'],
            ['first' => 'Nadine', 'last' => 'Kapinga', 'email' => 'nadine.kap@monrespro.local', 'phone' => '+32 460 600 001', 'agency' => $this->bruxelles, 'locker' => 'MRP-BXL-0002'],
            ['first' => 'Olivier', 'last' => 'Ngandu', 'email' => 'olivier.ng@monrespro.local', 'phone' => '+32 460 600 002', 'agency' => $this->bruxelles, 'locker' => 'MRP-BXL-0003'],
            ['first' => 'Francine', 'last' => 'Kabamba', 'email' => 'francine.kab@monrespro.local', 'phone' => '+33 6 00 00 01', 'agency' => $this->paris, 'locker' => 'MRP-PAR-0001'],
            ['first' => 'Didier', 'last' => 'Mutombo', 'email' => 'didier.mut@monrespro.local', 'phone' => '+33 6 00 00 02', 'agency' => $this->paris, 'locker' => 'MRP-PAR-0002'],
        ];

        foreach ($clients as $c) {
            $agency = $c['agency'];
            if (! $agency) {
                continue;
            }

            $profile = Profile::updateOrCreate(
                ['email' => $c['email']],
                ['first_name' => $c['first'], 'last_name' => $c['last'], 'phone' => $c['phone'], 'agency_id' => $agency->id, 'is_active' => true, 'is_staff' => false, 'is_client' => true]
            );

            $user = User::updateOrCreate(
                ['email' => $c['email']],
                ['name' => $c['first'].' '.$c['last'], 'password' => Hash::make('password'), 'profile_id' => $profile->id, 'agency_id' => $agency->id, 'theme_preference' => 'system']
            );

            $user->syncRoles(['client']);

            Locker::updateOrCreate(
                ['code' => $c['locker']],
                ['profile_id' => $profile->id, 'user_id' => $user->id, 'formatted_address' => "Monrespro Logistics – Casier {$c['locker']}"]
            );

            $this->extraClients[$c['email']] = $user;
        }
    }

    // ─── Profils destinataires (non-utilisateurs) ───────────────────────

    private function seedRecipientProfiles(): void
    {
        $this->command?->info('Profils destinataires…');

        $recipients = [
            ['first' => 'Papa', 'last' => 'Kalala', 'email' => 'papa.kalala@gmail.com', 'phone' => '+243 999 100 001', 'agency' => $this->kinshasa],
            ['first' => 'Maman', 'last' => 'Ngoy', 'email' => 'maman.ngoy@gmail.com', 'phone' => '+243 999 100 002', 'agency' => $this->kinshasa],
            ['first' => 'Tonton', 'last' => 'Ilunga', 'email' => 'tonton.ilunga@gmail.com', 'phone' => '+243 999 100 003', 'agency' => $this->kinshasa],
            ['first' => 'Sœur', 'last' => 'Mwamba', 'email' => 'soeur.mwamba@gmail.com', 'phone' => '+243 999 100 004', 'agency' => $this->kinshasa],
            ['first' => 'Cousin', 'last' => 'Kabongo', 'email' => 'cousin.kabongo@gmail.com', 'phone' => '+243 999 100 005', 'agency' => $this->kinshasa],
            ['first' => 'Amie', 'last' => 'Laurent', 'email' => 'amie.laurent@outlook.com', 'phone' => '+32 470 100 001', 'agency' => $this->bruxelles],
            ['first' => 'Collègue', 'last' => 'Dubois', 'email' => 'collegue.dubois@outlook.com', 'phone' => '+33 7 00 00 01', 'agency' => $this->paris],
        ];

        foreach ($recipients as $r) {
            if (! $r['agency']) {
                continue;
            }
            $this->recipientProfiles[$r['email']] = Profile::updateOrCreate(
                ['email' => $r['email']],
                ['first_name' => $r['first'], 'last_name' => $r['last'], 'phone' => $r['phone'], 'agency_id' => $r['agency']->id, 'is_active' => true, 'is_staff' => false, 'is_client' => false]
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
            ['owner' => $client1Profile->id, 'contact_email' => 'papa.kalala@gmail.com', 'alias' => 'Papa', 'is_default' => true],
            ['owner' => $client1Profile->id, 'contact_email' => 'soeur.mwamba@gmail.com', 'alias' => 'Ma sœur', 'is_default' => false],
            ['owner' => $client2Profile->id, 'contact_email' => 'maman.ngoy@gmail.com', 'alias' => 'Maman', 'is_default' => true],
            ['owner' => $client2Profile->id, 'contact_email' => 'cousin.kabongo@gmail.com', 'alias' => 'Cousin', 'is_default' => false],
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

        $locker1 = Locker::where('code', 'MRP-KIN-0001')->first();
        $locker2 = Locker::where('code', 'MRP-KIN-0002')->first();
        $locker3 = Locker::where('code', 'MRP-BXL-0001')->first();

        $preAlerts = [
            ['reference_code' => 'ASN-0001', 'user_id' => $this->client1->id, 'locker_id' => $locker1?->id, 'status' => ShipmentStatus::PendingDropOff, 'merchant_name' => 'Amazon', 'vendor_tracking_number' => 'TBA302456789', 'carrier_name' => 'DHL', 'description' => 'MacBook Air M3 commandé sur Amazon.fr', 'declared_value' => 1299.00, 'value_currency' => 'EUR', 'declared_weight_kg' => 2.5, 'purchase_date' => now()->subDays(8), 'estimated_arrival_date' => now()->addDays(5)],
            ['reference_code' => 'ASN-0002', 'user_id' => $this->client1->id, 'locker_id' => $locker1?->id, 'status' => ShipmentStatus::ReceivedAtHub, 'merchant_name' => 'Apple', 'vendor_tracking_number' => '1Z999AA10123456784', 'carrier_name' => 'UPS', 'description' => 'iPhone 16 Pro Max 256GB', 'declared_value' => 1199.00, 'value_currency' => 'EUR', 'declared_weight_kg' => 0.5, 'purchase_date' => now()->subDays(15), 'estimated_arrival_date' => now()->subDays(2)],
            ['reference_code' => 'ASN-0003', 'user_id' => $this->client2->id, 'locker_id' => $locker2?->id, 'status' => ShipmentStatus::PendingDropOff, 'merchant_name' => 'Nike', 'vendor_tracking_number' => '420221019205590020113', 'carrier_name' => 'Bpost', 'description' => 'Nike Air Force 1 x2 paires', 'declared_value' => 220.00, 'value_currency' => 'EUR', 'declared_weight_kg' => 2.0, 'purchase_date' => now()->subDays(5), 'estimated_arrival_date' => now()->addDays(3)],
            ['reference_code' => 'ASN-0004', 'user_id' => $this->client3->id, 'locker_id' => $locker3?->id, 'status' => ShipmentStatus::ReceivedAtHub, 'merchant_name' => 'Decathlon', 'vendor_tracking_number' => 'LP123456789BE', 'carrier_name' => 'Bpost', 'description' => 'Vélo d\'appartement pliable', 'declared_value' => 349.99, 'value_currency' => 'EUR', 'declared_weight_kg' => 22.0, 'purchase_date' => now()->subDays(12), 'estimated_arrival_date' => now()->subDays(1)],
            ['reference_code' => 'ASN-0005', 'user_id' => $this->client1->id, 'locker_id' => $locker1?->id, 'status' => ShipmentStatus::Delivered, 'merchant_name' => 'Sephora', 'vendor_tracking_number' => 'FR9876543210', 'carrier_name' => 'Colissimo', 'description' => 'Coffret parfums Dior', 'declared_value' => 180.00, 'value_currency' => 'EUR', 'declared_weight_kg' => 1.2, 'purchase_date' => now()->subDays(45), 'estimated_arrival_date' => now()->subDays(30)],
            ['reference_code' => 'ASN-0006', 'user_id' => $this->client2->id, 'locker_id' => $locker2?->id, 'status' => ShipmentStatus::InTransit, 'merchant_name' => 'AliExpress', 'vendor_tracking_number' => 'LY123456789CN', 'carrier_name' => 'China Post', 'description' => 'Lot accessoires Samsung Galaxy', 'declared_value' => 45.00, 'value_currency' => 'USD', 'declared_weight_kg' => 0.3, 'purchase_date' => now()->subDays(20), 'estimated_arrival_date' => now()->addDays(10)],
            ['reference_code' => 'ASN-0007', 'user_id' => $this->extraClients['marie.lukusa@monrespro.local']?->id, 'locker_id' => Locker::where('code', 'MRP-KIN-0003')->first()?->id, 'status' => ShipmentStatus::PendingDropOff, 'merchant_name' => 'Zara', 'vendor_tracking_number' => 'ZRA20260501001', 'carrier_name' => 'DPD', 'description' => 'Vêtements collection été 2026', 'declared_value' => 150.00, 'value_currency' => 'EUR', 'declared_weight_kg' => 3.0, 'purchase_date' => now()->subDays(3), 'estimated_arrival_date' => now()->addDays(7)],
            ['reference_code' => 'ASN-0008', 'user_id' => $this->extraClients['samuel.kib@monrespro.local']?->id, 'locker_id' => Locker::where('code', 'MRP-KIN-0004')->first()?->id, 'status' => ShipmentStatus::Expired, 'merchant_name' => 'eBay', 'vendor_tracking_number' => null, 'carrier_name' => null, 'description' => 'Pièce détachée BMW (non déposé)', 'declared_value' => 85.00, 'value_currency' => 'EUR', 'declared_weight_kg' => 4.0, 'purchase_date' => now()->subDays(60), 'estimated_arrival_date' => now()->subDays(50)],
        ];

        foreach ($preAlerts as $data) {
            PreAlert::updateOrCreate(['reference_code' => $data['reference_code']], $data);
        }
    }

    // ─── Colis Clients (CustomerPackages) ───────────────────────────────

    private function seedCustomerPackages(): void
    {
        $this->command?->info('Colis clients…');

        $locker1 = Locker::where('code', 'MRP-KIN-0001')->first();
        $locker2 = Locker::where('code', 'MRP-KIN-0002')->first();
        $preAlert2 = PreAlert::where('reference_code', 'ASN-0002')->first();
        $preAlert5 = PreAlert::where('reference_code', 'ASN-0005')->first();

        $packages = [
            ['reference_code' => 'PKG-0001', 'user_id' => $this->client1->id, 'agency_id' => $this->kinshasa->id, 'locker_id' => $locker1?->id, 'pre_alert_id' => $preAlert2?->id, 'status' => ShipmentStatus::ReceivedAtHub, 'description' => 'iPhone 16 Pro Max 256GB', 'merchant_name' => 'Apple', 'weight_kg' => 0.480, 'length_cm' => 20, 'width_cm' => 15, 'height_cm' => 8, 'declared_value' => 1199.00, 'value_currency' => 'EUR', 'received_at' => now()->subDays(2), 'received_by' => $this->operator->id, 'condition_notes' => 'Emballage intact, scellé d\'origine.'],
            ['reference_code' => 'PKG-0002', 'user_id' => $this->client1->id, 'agency_id' => $this->kinshasa->id, 'locker_id' => $locker1?->id, 'pre_alert_id' => $preAlert5?->id, 'status' => ShipmentStatus::Delivered, 'description' => 'Coffret parfums Dior', 'merchant_name' => 'Sephora', 'weight_kg' => 1.100, 'length_cm' => 30, 'width_cm' => 25, 'height_cm' => 12, 'declared_value' => 180.00, 'value_currency' => 'EUR', 'shipping_cost' => 27.00, 'total_cost' => 207.00, 'received_at' => now()->subDays(30), 'received_by' => $this->operator->id],
            ['reference_code' => 'PKG-0003', 'user_id' => $this->client2->id, 'agency_id' => $this->kinshasa->id, 'locker_id' => $locker2?->id, 'status' => ShipmentStatus::ReceivedAtHub, 'description' => 'Chaussures Nike Air Force 1', 'merchant_name' => 'Nike', 'weight_kg' => 1.800, 'length_cm' => 35, 'width_cm' => 25, 'height_cm' => 15, 'declared_value' => 110.00, 'value_currency' => 'EUR', 'received_at' => now()->subDays(1), 'received_by' => $this->operator->id],
            ['reference_code' => 'PKG-0004', 'user_id' => $this->client3->id, 'agency_id' => $this->bruxelles?->id, 'locker_id' => Locker::where('code', 'MRP-BXL-0001')->first()?->id, 'status' => ShipmentStatus::ReadyForDispatch, 'description' => 'Vélo d\'appartement pliable', 'merchant_name' => 'Decathlon', 'weight_kg' => 21.500, 'length_cm' => 120, 'width_cm' => 45, 'height_cm' => 60, 'declared_value' => 349.99, 'value_currency' => 'EUR', 'shipping_cost' => 322.50, 'total_cost' => 672.49, 'received_at' => now()->subDays(1), 'received_by' => $this->adminBxl->id],
        ];

        foreach ($packages as $data) {
            CustomerPackage::updateOrCreate(['reference_code' => $data['reference_code']], $data);
        }
    }

    // ─── Expéditions + Items + Logs ─────────────────────────────────────

    private function seedShipments(): void
    {
        $this->command?->info('Expéditions, items et logs…');

        $hubBxl = Hub::where('code', 'HUB-BXL')->first();
        $hubKin = Hub::where('code', 'HUB-KIN')->first();
        $hubPar = Hub::where('code', 'HUB-PAR')->first();
        $hubGz = Hub::where('code', 'HUB-GZ')->first();

        $catElec = ArticleCategory::where('name', 'like', 'Électronique%')->first();
        $catVet = ArticleCategory::where('name', 'like', 'Vêtements%')->first();
        $catBeaute = ArticleCategory::where('name', 'like', 'Beauté%')->first();
        $catDoc = ArticleCategory::where('name', 'like', 'Documents%')->first();
        $catMaison = ArticleCategory::where('name', 'like', 'Maison%')->first();
        $catSport = ArticleCategory::where('name', 'like', 'Sport%')->first();
        $catAuto = ArticleCategory::where('name', 'like', 'Auto%')->first();

        $senderProfile1 = $this->client1->profile;
        $senderProfile2 = $this->client2->profile;
        $senderProfile3 = $this->client3->profile;
        $recipPapa = $this->recipientProfiles['papa.kalala@gmail.com'] ?? null;
        $recipMaman = $this->recipientProfiles['maman.ngoy@gmail.com'] ?? null;
        $recipTonton = $this->recipientProfiles['tonton.ilunga@gmail.com'] ?? null;
        $recipSoeur = $this->recipientProfiles['soeur.mwamba@gmail.com'] ?? null;

        $shipmentData = [
            // 1. Livré — Bruxelles → Kinshasa (fret aérien) — il y a 60 jours
            [
                'tracking' => 'EXP2026030001', 'doc' => 'EXP6379850001',
                'sender' => $senderProfile3, 'recipient' => $recipPapa,
                'creator' => $this->adminBxl, 'agency' => $this->bruxelles,
                'origin' => $this->countryBE, 'dest' => $this->countryCD,
                'status' => ShipmentStatus::Delivered, 'flow' => 'standard',
                'hub' => $hubKin, 'weight' => 3.55, 'vol_w' => 4.0,
                'l' => 40, 'w' => 30, 'h' => 20,
                'value' => 450.00, 'currency' => 'USD', 'price' => 53.25,
                'payment_status' => 'paid', 'amount_paid' => 53.25, 'paid_at' => now()->subDays(58),
                'items' => [
                    ['desc' => 'Vêtements divers', 'qty' => 5, 'wt' => 2.5, 'val' => 300, 'cat' => $catVet],
                    ['desc' => 'Chaussures Nike', 'qty' => 2, 'wt' => 1.05, 'val' => 150, 'cat' => $catVet],
                ],
                'logs' => [
                    ['status' => ShipmentStatus::PendingDropOff, 'title' => 'Expédition créée', 'days_ago' => 65],
                    ['status' => ShipmentStatus::ReceivedAtHub, 'title' => 'Réceptionné à Bruxelles', 'days_ago' => 63],
                    ['status' => ShipmentStatus::ReadyForDispatch, 'title' => 'Prêt à l\'expédition', 'days_ago' => 62],
                    ['status' => ShipmentStatus::InTransit, 'title' => 'En transit BE → CD', 'days_ago' => 61],
                    ['status' => ShipmentStatus::CustomsHold, 'title' => 'Dédouanement en cours', 'days_ago' => 58],
                    ['status' => ShipmentStatus::ArrivedAtDestination, 'title' => 'Arrivé à Kinshasa', 'days_ago' => 57],
                    ['status' => ShipmentStatus::Delivered, 'title' => 'Livré au destinataire', 'days_ago' => 55],
                ],
                'created_ago' => 65,
            ],
            // 2. En transit — France → RDC (fret aérien)
            [
                'tracking' => 'EXP2026040002', 'doc' => 'EXP6379850002',
                'sender' => $senderProfile1, 'recipient' => $recipMaman,
                'creator' => $this->operator, 'agency' => $this->kinshasa,
                'origin' => $this->countryFR, 'dest' => $this->countryCD,
                'status' => ShipmentStatus::InTransit, 'flow' => 'standard',
                'hub' => $hubPar, 'weight' => 8.2, 'vol_w' => 12.0,
                'l' => 60, 'w' => 40, 'h' => 25,
                'value' => 850.00, 'currency' => 'EUR', 'price' => 180.00,
                'payment_status' => 'paid', 'amount_paid' => 180.00, 'paid_at' => now()->subDays(8),
                'items' => [
                    ['desc' => 'Laptop HP Pavilion 15"', 'qty' => 1, 'wt' => 2.1, 'val' => 650, 'cat' => $catElec],
                    ['desc' => 'Souris + clavier sans fil Logitech', 'qty' => 1, 'wt' => 0.8, 'val' => 80, 'cat' => $catElec],
                    ['desc' => 'Sac à dos laptop Samsonite', 'qty' => 1, 'wt' => 1.2, 'val' => 120, 'cat' => $catVet],
                ],
                'logs' => [
                    ['status' => ShipmentStatus::PendingDropOff, 'title' => 'Expédition créée', 'days_ago' => 12],
                    ['status' => ShipmentStatus::ReceivedAtHub, 'title' => 'Réceptionné à Paris', 'days_ago' => 10],
                    ['status' => ShipmentStatus::ReadyForDispatch, 'title' => 'Prêt à l\'expédition', 'days_ago' => 9],
                    ['status' => ShipmentStatus::InTransit, 'title' => 'En transit FR → CD', 'days_ago' => 7],
                ],
                'created_ago' => 12,
            ],
            // 3. En douane — Chine → RDC (maritime)
            [
                'tracking' => 'EXP2026040003', 'doc' => 'EXP6379850003',
                'sender' => $senderProfile2, 'recipient' => $recipTonton,
                'creator' => $this->operator, 'agency' => $this->kinshasa,
                'origin' => $this->countryCN, 'dest' => $this->countryCD,
                'status' => ShipmentStatus::CustomsHold, 'flow' => 'standard',
                'hub' => $hubGz, 'weight' => 120.0, 'vol_w' => 200.0,
                'l' => 150, 'w' => 100, 'h' => 80,
                'value' => 3500.00, 'currency' => 'USD', 'price' => 1440.00,
                'payment_status' => 'partial', 'amount_paid' => 800.00, 'paid_at' => now()->subDays(30),
                'items' => [
                    ['desc' => 'Groupe électrogène 5kVA', 'qty' => 1, 'wt' => 80, 'val' => 2200, 'cat' => $catAuto],
                    ['desc' => 'Pièces de rechange groupe', 'qty' => 1, 'wt' => 15, 'val' => 500, 'cat' => $catAuto],
                    ['desc' => 'Outils atelier complet', 'qty' => 1, 'wt' => 25, 'val' => 800, 'cat' => $catAuto],
                ],
                'logs' => [
                    ['status' => ShipmentStatus::PendingDropOff, 'title' => 'Expédition créée', 'days_ago' => 45],
                    ['status' => ShipmentStatus::ReceivedAtHub, 'title' => 'Réceptionné à Guangzhou', 'days_ago' => 42],
                    ['status' => ShipmentStatus::InTransit, 'title' => 'Départ maritime GZ → Matadi', 'days_ago' => 38],
                    ['status' => ShipmentStatus::CustomsHold, 'title' => 'Blocage douane Matadi', 'days_ago' => 5],
                ],
                'created_ago' => 45,
            ],
            // 4. Prêt à l'expédition — BE → CD
            [
                'tracking' => 'EXP2026050004', 'doc' => 'EXP6379850004',
                'sender' => $senderProfile3, 'recipient' => $recipSoeur,
                'creator' => $this->adminBxl, 'agency' => $this->bruxelles,
                'origin' => $this->countryBE, 'dest' => $this->countryCD,
                'status' => ShipmentStatus::ReadyForDispatch, 'flow' => 'standard',
                'hub' => $hubBxl, 'weight' => 5.0, 'vol_w' => 6.5,
                'l' => 50, 'w' => 35, 'h' => 22,
                'value' => 320.00, 'currency' => 'EUR', 'price' => 75.00,
                'payment_status' => 'paid', 'amount_paid' => 75.00, 'paid_at' => now()->subDays(2),
                'items' => [
                    ['desc' => 'Cosmétiques et soins L\'Oréal', 'qty' => 8, 'wt' => 3.5, 'val' => 220, 'cat' => $catBeaute],
                    ['desc' => 'Bijoux fantaisie Pandora', 'qty' => 3, 'wt' => 0.3, 'val' => 100, 'cat' => $catBeaute],
                ],
                'logs' => [
                    ['status' => ShipmentStatus::PendingDropOff, 'title' => 'Expédition créée', 'days_ago' => 5],
                    ['status' => ShipmentStatus::ReceivedAtHub, 'title' => 'Réceptionné à Bruxelles', 'days_ago' => 3],
                    ['status' => ShipmentStatus::ReadyForDispatch, 'title' => 'Prêt à l\'expédition', 'days_ago' => 1],
                ],
                'created_ago' => 5,
            ],
            // 5. En attente de dépôt
            [
                'tracking' => 'EXP2026050005', 'doc' => 'EXP6379850005',
                'sender' => $senderProfile1, 'recipient' => $recipPapa,
                'creator' => $this->operator, 'agency' => $this->kinshasa,
                'origin' => $this->countryBE, 'dest' => $this->countryCD,
                'status' => ShipmentStatus::PendingDropOff, 'flow' => 'standard',
                'hub' => null, 'weight' => 1.5, 'vol_w' => null,
                'l' => 30, 'w' => 22, 'h' => 5,
                'value' => 50.00, 'currency' => 'EUR', 'price' => 22.50,
                'payment_status' => 'unpaid', 'amount_paid' => 0, 'paid_at' => null,
                'items' => [
                    ['desc' => 'Documents administratifs', 'qty' => 1, 'wt' => 0.5, 'val' => 0, 'cat' => $catDoc],
                    ['desc' => 'Photos de famille encadrées', 'qty' => 3, 'wt' => 1.0, 'val' => 50, 'cat' => $catMaison],
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
                'creator' => $this->operator, 'agency' => $this->kinshasa,
                'origin' => $this->countryBE, 'dest' => $this->countryCD,
                'status' => ShipmentStatus::Delivered, 'flow' => 'standard',
                'hub' => $hubKin, 'weight' => 2.0, 'vol_w' => 2.5,
                'l' => 35, 'w' => 25, 'h' => 15,
                'value' => 200.00, 'currency' => 'EUR', 'price' => 30.00,
                'payment_status' => 'paid', 'amount_paid' => 30.00, 'paid_at' => now()->subDays(88),
                'items' => [
                    ['desc' => 'Médicaments autorisés', 'qty' => 10, 'wt' => 1.5, 'val' => 150, 'cat' => $catBeaute],
                    ['desc' => 'Compléments alimentaires', 'qty' => 3, 'wt' => 0.5, 'val' => 50, 'cat' => $catBeaute],
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
            // 7. Livré il y a 30 jours
            [
                'tracking' => 'EXP2026040007', 'doc' => 'EXP6379850007',
                'sender' => $senderProfile1, 'recipient' => $recipSoeur,
                'creator' => $this->operator, 'agency' => $this->kinshasa,
                'origin' => $this->countryFR, 'dest' => $this->countryCD,
                'status' => ShipmentStatus::Delivered, 'flow' => 'standard',
                'hub' => $hubKin, 'weight' => 4.2, 'vol_w' => 5.0,
                'l' => 45, 'w' => 35, 'h' => 20,
                'value' => 380.00, 'currency' => 'EUR', 'price' => 84.00,
                'payment_status' => 'paid', 'amount_paid' => 84.00, 'paid_at' => now()->subDays(32),
                'items' => [
                    ['desc' => 'Robe de soirée Zara', 'qty' => 2, 'wt' => 1.0, 'val' => 180, 'cat' => $catVet],
                    ['desc' => 'Sac à main Michael Kors', 'qty' => 1, 'wt' => 0.8, 'val' => 200, 'cat' => $catVet],
                ],
                'logs' => [
                    ['status' => ShipmentStatus::PendingDropOff, 'title' => 'Expédition créée', 'days_ago' => 40],
                    ['status' => ShipmentStatus::ReceivedAtHub, 'title' => 'Réceptionné à Paris', 'days_ago' => 38],
                    ['status' => ShipmentStatus::InTransit, 'title' => 'En transit', 'days_ago' => 36],
                    ['status' => ShipmentStatus::ArrivedAtDestination, 'title' => 'Arrivé à Kinshasa', 'days_ago' => 32],
                    ['status' => ShipmentStatus::Delivered, 'title' => 'Livré', 'days_ago' => 30],
                ],
                'created_ago' => 40,
            ],
            // 8. Arrivé à destination — en attente livraison
            [
                'tracking' => 'EXP2026050008', 'doc' => 'EXP6379850008',
                'sender' => $senderProfile1, 'recipient' => $recipPapa,
                'creator' => $this->operator, 'agency' => $this->kinshasa,
                'origin' => $this->countryUS, 'dest' => $this->countryCD,
                'status' => ShipmentStatus::ArrivedAtDestination, 'flow' => 'standard',
                'hub' => $hubKin, 'weight' => 6.0, 'vol_w' => 8.0,
                'l' => 55, 'w' => 40, 'h' => 30,
                'value' => 520.00, 'currency' => 'USD', 'price' => 180.00,
                'payment_status' => 'paid', 'amount_paid' => 180.00, 'paid_at' => now()->subDays(15),
                'items' => [
                    ['desc' => 'Tablette Samsung Galaxy Tab S9', 'qty' => 1, 'wt' => 0.6, 'val' => 350, 'cat' => $catElec],
                    ['desc' => 'Étui + stylet Samsung', 'qty' => 1, 'wt' => 0.3, 'val' => 70, 'cat' => $catElec],
                    ['desc' => 'Casque Sony WH-1000XM5', 'qty' => 1, 'wt' => 0.3, 'val' => 100, 'cat' => $catElec],
                ],
                'logs' => [
                    ['status' => ShipmentStatus::PendingDropOff, 'title' => 'Expédition créée', 'days_ago' => 18],
                    ['status' => ShipmentStatus::ReceivedAtHub, 'title' => 'Réceptionné à Miami', 'days_ago' => 16],
                    ['status' => ShipmentStatus::InTransit, 'title' => 'En transit US → CD', 'days_ago' => 14],
                    ['status' => ShipmentStatus::CustomsHold, 'title' => 'Dédouanement', 'days_ago' => 5],
                    ['status' => ShipmentStatus::ArrivedAtDestination, 'title' => 'Arrivé à Kinshasa', 'days_ago' => 3],
                ],
                'created_ago' => 18,
            ],
            // 9. Échec livraison
            [
                'tracking' => 'EXP2026050009', 'doc' => 'EXP6379850009',
                'sender' => $senderProfile2, 'recipient' => $recipTonton,
                'creator' => $this->operator, 'agency' => $this->kinshasa,
                'origin' => $this->countryBE, 'dest' => $this->countryCD,
                'status' => ShipmentStatus::DeliveryFailed, 'flow' => 'standard',
                'hub' => $hubKin, 'weight' => 2.8, 'vol_w' => 3.5,
                'l' => 40, 'w' => 30, 'h' => 20,
                'value' => 290.00, 'currency' => 'EUR', 'price' => 42.00,
                'payment_status' => 'paid', 'amount_paid' => 42.00, 'paid_at' => now()->subDays(10),
                'items' => [
                    ['desc' => 'Équipement de sport Decathlon', 'qty' => 3, 'wt' => 2.8, 'val' => 290, 'cat' => $catSport],
                ],
                'logs' => [
                    ['status' => ShipmentStatus::PendingDropOff, 'title' => 'Expédition créée', 'days_ago' => 15],
                    ['status' => ShipmentStatus::ReceivedAtHub, 'title' => 'Réceptionné', 'days_ago' => 13],
                    ['status' => ShipmentStatus::InTransit, 'title' => 'En transit', 'days_ago' => 11],
                    ['status' => ShipmentStatus::ArrivedAtDestination, 'title' => 'Arrivé', 'days_ago' => 5],
                    ['status' => ShipmentStatus::DeliveryFailed, 'title' => 'Destinataire absent', 'days_ago' => 3],
                ],
                'created_ago' => 15,
            ],
            // 10. Annulé
            [
                'tracking' => 'EXP2026050010', 'doc' => 'EXP6379850010',
                'sender' => $senderProfile1, 'recipient' => $recipPapa,
                'creator' => $this->operator, 'agency' => $this->kinshasa,
                'origin' => $this->countryBE, 'dest' => $this->countryCD,
                'status' => ShipmentStatus::Cancelled, 'flow' => 'standard',
                'hub' => null, 'weight' => 1.0, 'vol_w' => null,
                'l' => 25, 'w' => 20, 'h' => 10,
                'value' => 80.00, 'currency' => 'EUR', 'price' => 15.00,
                'payment_status' => 'unpaid', 'amount_paid' => 0, 'paid_at' => null,
                'items' => [
                    ['desc' => 'Livre annulé', 'qty' => 1, 'wt' => 1.0, 'val' => 80, 'cat' => $catDoc],
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
                'sender' => $this->extraClients['marie.lukusa@monrespro.local']?->profile, 'recipient' => $recipPapa,
                'creator' => $this->operator, 'agency' => $this->kinshasa,
                'origin' => $this->countryBE, 'dest' => $this->countryCD,
                'status' => ShipmentStatus::ReceivedAtHub, 'flow' => 'standard',
                'hub' => $hubBxl, 'weight' => 7.5, 'vol_w' => 9.0,
                'l' => 50, 'w' => 40, 'h' => 30,
                'value' => 600.00, 'currency' => 'EUR', 'price' => 112.50,
                'payment_status' => 'unpaid', 'amount_paid' => 0, 'paid_at' => null,
                'items' => [
                    ['desc' => 'Mixeur Moulinex', 'qty' => 1, 'wt' => 4.5, 'val' => 350, 'cat' => $catMaison],
                    ['desc' => 'Set de casseroles Tefal', 'qty' => 1, 'wt' => 3.0, 'val' => 250, 'cat' => $catMaison],
                ],
                'logs' => [
                    ['status' => ShipmentStatus::PendingDropOff, 'title' => 'Expédition créée', 'days_ago' => 4],
                    ['status' => ShipmentStatus::ReceivedAtHub, 'title' => 'Réceptionné à Bruxelles', 'days_ago' => 2],
                ],
                'created_ago' => 4,
            ],
            // 12. Problème signalé
            [
                'tracking' => 'EXP2026050012', 'doc' => 'EXP6379850012',
                'sender' => $this->extraClients['samuel.kib@monrespro.local']?->profile, 'recipient' => $recipMaman,
                'creator' => $this->operator, 'agency' => $this->kinshasa,
                'origin' => $this->countryFR, 'dest' => $this->countryCD,
                'status' => ShipmentStatus::IssueReported, 'flow' => 'standard',
                'hub' => $hubPar, 'weight' => 3.0, 'vol_w' => 3.5,
                'l' => 40, 'w' => 30, 'h' => 15,
                'value' => 250.00, 'currency' => 'EUR', 'price' => 60.00,
                'payment_status' => 'paid', 'amount_paid' => 60.00, 'paid_at' => now()->subDays(8),
                'items' => [
                    ['desc' => 'Vase en cristal (fragile)', 'qty' => 1, 'wt' => 3.0, 'val' => 250, 'cat' => $catMaison],
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
            ['agency_id' => $this->bruxelles?->id, 'status' => ShipmentStatus::Delivered]
        );

        $regroup2 = Regroupement::updateOrCreate(
            ['batch_number' => 'GRP-2026-002'],
            ['agency_id' => $this->bruxelles?->id, 'status' => ShipmentStatus::ReadyForDispatch]
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
            ['user_id' => $this->client1->id, 'agency_id' => $this->kinshasa->id, 'shipment_id' => $shipment8?->id, 'status' => PickupStatus::Completed, 'assigned_driver_id' => $this->driver->id, 'latitude' => -4.3250, 'longitude' => 15.3100, 'address_text' => 'Avenue Lumumba 45, Gombe, Kinshasa', 'requested_window' => 'Matin 8h-12h', 'completed_at' => now()->subDays(3), 'completion_notes' => 'Livré avec succès au bureau Gombe.'],
            ['user_id' => $this->client2->id, 'agency_id' => $this->kinshasa->id, 'shipment_id' => null, 'status' => PickupStatus::DriverAssigned, 'assigned_driver_id' => $this->driver->id, 'latitude' => -4.3400, 'longitude' => 15.2800, 'address_text' => 'Avenue Kasa-Vubu 120, Barumbu, Kinshasa', 'requested_window' => 'Après-midi 14h-18h'],
            ['user_id' => $this->client1->id, 'agency_id' => $this->kinshasa->id, 'shipment_id' => $shipment5?->id, 'status' => PickupStatus::Draft, 'assigned_driver_id' => null, 'latitude' => -4.3100, 'longitude' => 15.3200, 'address_text' => 'Boulevard du 30 Juin 88, Gombe, Kinshasa', 'requested_window' => 'Demain matin'],
            ['user_id' => $this->extraClients['rachel.mbuyi@monrespro.local']?->id, 'agency_id' => $this->kinshasa->id, 'shipment_id' => null, 'status' => PickupStatus::Failed, 'assigned_driver_id' => $this->driver->id, 'latitude' => -4.3500, 'longitude' => 15.3400, 'address_text' => 'Avenue de la Libération 33, Limete', 'requested_window' => 'Hier matin', 'failure_reason' => 'Client absent, porte fermée'],
            ['user_id' => $this->extraClients['christian.nseka@monrespro.local']?->id, 'agency_id' => $this->kinshasa->id, 'shipment_id' => null, 'status' => PickupStatus::EnRoute, 'assigned_driver_id' => $this->driver->id, 'latitude' => -4.3600, 'longitude' => 15.2900, 'address_text' => 'Quartier Matonge, Kalamu', 'requested_window' => 'Maintenant'],
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
            $agency = $shipment->agency ?? $this->kinshasa;

            $invoice = Invoice::updateOrCreate(
                ['shipment_id' => $shipment->id],
                [
                    'invoice_number' => 'INV-2026-'.str_pad($invNum, 5, '0', STR_PAD_LEFT),
                    'user_id' => $user->id,
                    'agency_id' => $agency->id,
                    'amount' => $shipment->calculated_price,
                    'base_amount' => $shipment->calculated_price,
                    'currency' => $shipment->currency ?? 'USD',
                    'status' => $shipment->payment_status === 'paid' ? 'paid' : 'partial',
                    'due_at' => $shipment->created_at->addDays(15),
                    'paid_at' => $shipment->paid_at,
                ]
            );

            ShipmentPayment::updateOrCreate(
                ['shipment_id' => $shipment->id, 'amount' => $shipment->amount_paid],
                [
                    'payment_method' => collect(['M-Pesa', 'Orange Money', 'Virement SEPA', 'Espèces USD', 'Airtel Money'])->random(),
                    'reference' => 'PAY-'.strtoupper(Str::random(8)),
                    'notes' => 'Paiement reçu',
                    'recorded_by_user_id' => $this->operator->id,
                ]
            );

            $invNum++;
        }

        $extraInvoices = [
            ['inv' => 'INV-2026-00020', 'user' => $this->client1, 'amount' => 45.00, 'status' => 'pending', 'days_ago' => 5],
            ['inv' => 'INV-2026-00021', 'user' => $this->client2, 'amount' => 120.00, 'status' => 'overdue', 'days_ago' => 30],
            ['inv' => 'INV-2026-00022', 'user' => $this->client3, 'amount' => 322.50, 'status' => 'pending', 'days_ago' => 2],
        ];

        foreach ($extraInvoices as $ei) {
            Invoice::updateOrCreate(
                ['invoice_number' => $ei['inv']],
                [
                    'user_id' => $ei['user']->id,
                    'agency_id' => $this->kinshasa->id,
                    'amount' => $ei['amount'],
                    'base_amount' => $ei['amount'],
                    'currency' => 'USD',
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
            ['type' => 'debit', 'amount' => 250.00, 'desc' => 'Achat carburant véhicule livraison', 'days_ago' => 10],
            ['type' => 'debit', 'amount' => 1500.00, 'desc' => 'Loyer entrepôt Kinshasa – Mai 2026', 'days_ago' => 3],
            ['type' => 'debit', 'amount' => 800.00, 'desc' => 'Salaires chauffeurs – Avril 2026', 'days_ago' => 15],
            ['type' => 'credit', 'amount' => 350.00, 'desc' => 'Commission assistance achat – Lot Mars', 'days_ago' => 40],
            ['type' => 'debit', 'amount' => 120.00, 'desc' => 'Fournitures bureau (emballages)', 'days_ago' => 7],
        ];

        foreach ($extraEntries as $entry) {
            LedgerEntry::create([
                'agency_id' => $this->kinshasa->id,
                'user_id' => $this->adminKin->id,
                'amount' => $entry['amount'],
                'currency' => 'USD',
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
            ['title' => 'Accusé de réception', 'body' => "Bonjour,\n\nNous avons bien reçu votre réclamation et l'avons enregistrée sous la référence {{ticket_ref}}. Un membre de notre équipe vous recontactera sous 24h.\n\nCordialement,\nÉquipe SAV Monrespro", 'category' => 'general', 'sort_order' => 1],
            ['title' => 'Demande de preuve', 'body' => "Bonjour,\n\nPour traiter votre réclamation, nous avons besoin de photos du colis et de son contenu. Merci de les joindre à ce ticket.\n\nCordialement,\nÉquipe SAV Monrespro", 'category' => 'lost_damaged', 'sort_order' => 2],
            ['title' => 'Retard — mise à jour', 'body' => "Bonjour,\n\nVotre colis est actuellement en transit et subit un retard dû à {{raison}}. Nous estimons la livraison sous {{delai}} jours ouvrés.\n\nNous vous prions de nous excuser pour ce désagrément.\n\nCordialement,\nÉquipe SAV Monrespro", 'category' => 'delivery_delay', 'sort_order' => 3],
            ['title' => 'Remboursement initié', 'body' => "Bonjour,\n\nSuite à votre demande, nous avons initié un remboursement de {{montant}} {{devise}}. Le traitement prend 5-10 jours ouvrés selon votre mode de paiement.\n\nCordialement,\nÉquipe SAV Monrespro", 'category' => 'refund', 'sort_order' => 4],
            ['title' => 'Ticket résolu', 'body' => "Bonjour,\n\nVotre réclamation a été traitée avec succès. Si vous avez d'autres questions, n'hésitez pas à nous contacter.\n\nMerci de votre confiance.\n\nCordialement,\nÉquipe SAV Monrespro", 'category' => 'general', 'sort_order' => 5],
            ['title' => 'En attente info client', 'body' => "Bonjour,\n\nNous avons besoin d'informations complémentaires pour traiter votre demande. Pourriez-vous nous fournir {{info_requise}} ?\n\nMerci d'avance.\n\nCordialement,\nÉquipe SAV Monrespro", 'category' => 'general', 'sort_order' => 6],
        ];

        foreach ($replies as $r) {
            SavQuickReply::updateOrCreate(
                ['agency_id' => $this->kinshasa->id, 'title' => $r['title']],
                array_merge($r, ['agency_id' => $this->kinshasa->id, 'is_active' => true])
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

        $tickets = [
            [
                'ref' => 'TKT-2026-0001', 'agency' => $this->kinshasa, 'client' => $this->client2, 'assigned' => $this->operator, 'created_by' => $this->client2,
                'category' => SavTicketCategory::DeliveryDelay, 'priority' => SavTicketPriority::Normal, 'status' => SavTicketStatus::InProgress,
                'channel' => 'web', 'subject' => 'Mon colis est en retard de 2 semaines',
                'description' => "Bonjour, mon colis EXP2026050009 devait arriver il y a 2 semaines mais il n'est toujours pas là. Le tracking montre 'échec de livraison'. Quand est-ce que je vais le recevoir ?",
                'related_type' => Shipment::class, 'related_id' => $shipment9?->id,
                'sla_deadline_at' => now()->addHours(48), 'first_response_at' => now()->subHours(2), 'days_ago' => 3,
                'messages' => [
                    ['user' => $this->client2, 'body' => "Bonjour, j'attends mon colis EXP2026050009 depuis 2 semaines. Le livreur n'est jamais passé. Merci de me donner une mise à jour.", 'internal' => false, 'days_ago' => 3],
                    ['user' => $this->operator, 'body' => "Bonjour Emmanuel, nous avons vérifié votre dossier. Le livreur a tenté un passage mais personne n'était disponible à l'adresse indiquée. Nous reprogrammons la livraison pour demain matin.", 'internal' => false, 'days_ago' => 2],
                    ['user' => $this->operator, 'body' => "Note interne : Contacter le livreur José pour confirmer la reprogrammation.", 'internal' => true, 'days_ago' => 2],
                ],
            ],
            [
                'ref' => 'TKT-2026-0002', 'agency' => $this->kinshasa, 'client' => $this->extraClients['samuel.kib@monrespro.local'], 'assigned' => $this->operator, 'created_by' => $this->operator,
                'category' => SavTicketCategory::LostDamaged, 'priority' => SavTicketPriority::Urgent, 'status' => SavTicketStatus::Escalated,
                'channel' => 'phone', 'subject' => 'Colis endommagé — vase en cristal cassé',
                'description' => "Client appelle : le colis EXP2026050012 contenant un vase en cristal est arrivé endommagé. Le vase est cassé en morceaux. Photos envoyées par WhatsApp.",
                'related_type' => Shipment::class, 'related_id' => $shipment12?->id,
                'sla_deadline_at' => now()->addHours(12), 'first_response_at' => now()->subHours(5), 'escalated_at' => now()->subHours(1), 'days_ago' => 2,
                'messages' => [
                    ['user' => $this->operator, 'body' => "Le client Samuel Kibangula a appelé pour signaler un colis endommagé. Photos reçues via WhatsApp montrant le vase cassé.", 'internal' => false, 'days_ago' => 2],
                    ['user' => $this->operator, 'body' => "Note interne : Vérifier l'assurance transport. Valeur déclarée 250 EUR. Escalader à l'admin pour décision remboursement.", 'internal' => true, 'days_ago' => 1],
                    ['user' => $this->adminKin, 'body' => "Dossier pris en charge. Je contacte le hub Paris pour le rapport de dommage. Remboursement partiel à envisager.", 'internal' => true, 'days_ago' => 0],
                ],
            ],
            [
                'ref' => 'TKT-2026-0003', 'agency' => $this->kinshasa, 'client' => $this->client1, 'assigned' => $this->operator, 'created_by' => $this->client1,
                'category' => SavTicketCategory::CustomsIssue, 'priority' => SavTicketPriority::Normal, 'status' => SavTicketStatus::WaitingClient,
                'channel' => 'web', 'subject' => 'Colis bloqué en douane depuis 5 jours',
                'description' => "Mon colis EXP2026040003 est bloqué en douane à Matadi depuis 5 jours. Pouvez-vous m'aider à débloquer la situation ?",
                'related_type' => Shipment::class, 'related_id' => $shipment3?->id,
                'sla_deadline_at' => now()->addHours(72), 'first_response_at' => now()->subDays(2), 'days_ago' => 5,
                'messages' => [
                    ['user' => $this->client1, 'body' => "Bonjour, mon colis maritime est bloqué en douane depuis 5 jours. Numéro EXP2026040003. C'est un groupe électrogène.", 'internal' => false, 'days_ago' => 5],
                    ['user' => $this->operator, 'body' => "Bonjour Grace, votre colis nécessite un document supplémentaire pour le dédouanement : la facture originale du fournisseur chinois. Pouvez-vous nous la transmettre ?", 'internal' => false, 'days_ago' => 3],
                ],
            ],
            [
                'ref' => 'TKT-2026-0004', 'agency' => $this->kinshasa, 'client' => $this->client1, 'assigned' => null, 'created_by' => $this->client1,
                'category' => SavTicketCategory::GeneralQuestion, 'priority' => SavTicketPriority::Low, 'status' => SavTicketStatus::Resolved,
                'channel' => 'web', 'subject' => 'Comment fonctionne le regroupement de colis ?',
                'description' => "Je voudrais comprendre comment fonctionne le service de regroupement. Est-ce que je peux regrouper des colis venant de différents magasins ?",
                'sla_deadline_at' => now()->subDays(5), 'first_response_at' => now()->subDays(8), 'resolved_at' => now()->subDays(6), 'days_ago' => 10,
                'messages' => [
                    ['user' => $this->client1, 'body' => "Bonjour, je voudrais savoir si je peux regrouper des colis de plusieurs magasins différents en un seul envoi vers Kinshasa ?", 'internal' => false, 'days_ago' => 10],
                    ['user' => $this->operator, 'body' => "Bonjour Grace ! Oui, c'est exactement le principe du regroupement. Vous pouvez faire livrer plusieurs colis à votre casier MRP-KIN-0001 chez notre entrepôt Bruxelles. Une fois tous les colis arrivés, nous les regroupons en un seul envoi, ce qui réduit considérablement les frais d'expédition. Vous bénéficiez de 45 jours de stockage gratuit.", 'internal' => false, 'days_ago' => 8],
                    ['user' => $this->client1, 'body' => "Super, merci beaucoup pour l'explication ! C'est très clair.", 'internal' => false, 'days_ago' => 7],
                ],
            ],
            [
                'ref' => 'TKT-2026-0005', 'agency' => $this->kinshasa, 'client' => $this->extraClients['esther.tshi@monrespro.local'], 'assigned' => $this->operator, 'created_by' => $this->extraClients['esther.tshi@monrespro.local'],
                'category' => SavTicketCategory::PaymentIssue, 'priority' => SavTicketPriority::Urgent, 'status' => SavTicketStatus::Open,
                'channel' => 'whatsapp', 'subject' => 'Paiement M-Pesa non pris en compte',
                'description' => "J'ai payé 150 USD via M-Pesa il y a 3 jours mais ma facture montre toujours 'impayé'. Voici le code de confirmation : MP20260509123456.",
                'sla_deadline_at' => now()->addHours(24), 'days_ago' => 1,
                'messages' => [
                    ['user' => $this->extraClients['esther.tshi@monrespro.local'], 'body' => "Bonjour, j'ai payé 150 USD via M-Pesa le 09/05/2026. Code confirmation : MP20260509123456. Mon compte montre toujours impayé. Merci de vérifier.", 'internal' => false, 'days_ago' => 1],
                ],
            ],
            [
                'ref' => 'TKT-2026-0006', 'agency' => $this->kinshasa, 'client' => $this->client2, 'assigned' => $this->operator, 'created_by' => $this->client2,
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

        $refunds = [
            [
                'reference_code' => 'RMB-0001',
                'refundable_type' => Shipment::class, 'refundable_id' => $shipment12?->id,
                'client_id' => $this->extraClients['samuel.kib@monrespro.local']?->id,
                'agency_id' => $this->kinshasa->id,
                'amount' => 125.00, 'currency' => 'EUR',
                'status' => RefundStatus::UnderReview,
                'reason' => 'Vase en cristal cassé à la réception. Assurance couvre 50% de la valeur déclarée.',
                'reason_category' => 'damaged',
                'payment_method' => 'M-Pesa',
                'reviewed_by' => $this->adminKin->id,
                'reviewed_at' => now()->subHours(3),
            ],
            [
                'reference_code' => 'RMB-0002',
                'refundable_type' => AssistedPurchase::class,
                'refundable_id' => AssistedPurchase::where('status', AssistedPurchaseStatus::CANCELLED)->first()?->id,
                'client_id' => $this->client2->id,
                'agency_id' => $this->kinshasa->id,
                'amount' => 0, 'currency' => 'USD',
                'status' => RefundStatus::Rejected,
                'reason' => 'Demande de remboursement pour achat assisté annulé.',
                'reason_category' => 'cancellation',
                'rejection_reason' => 'Aucun paiement effectué avant l\'annulation.',
                'reviewed_by' => $this->adminKin->id,
                'reviewed_at' => now()->subDays(5),
            ],
            [
                'reference_code' => 'RMB-0003',
                'refundable_type' => Shipment::class,
                'refundable_id' => Shipment::where('public_tracking', 'EXP2026030001')->first()?->id,
                'client_id' => $this->client3->id,
                'agency_id' => $this->bruxelles?->id,
                'amount' => 15.00, 'currency' => 'USD',
                'status' => RefundStatus::Completed,
                'reason' => 'Surcharge corrigée — erreur de pesée au hub.',
                'reason_category' => 'overcharge',
                'payment_method' => 'Virement SEPA',
                'reviewed_by' => $this->adminBxl->id, 'reviewed_at' => now()->subDays(50),
                'processed_by' => $this->adminBxl->id, 'processed_at' => now()->subDays(48),
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
            ['user' => $this->client1, 'type' => 'in_app', 'title' => 'Colis arrivé à destination', 'body' => 'Votre colis EXP2026050008 est arrivé à Kinshasa. Planifiez votre livraison.', 'action_url' => '/shipments/EXP2026050008', 'status' => 'sent', 'days_ago' => 3, 'read' => false],
            ['user' => $this->client1, 'type' => 'in_app', 'title' => 'Devis prêt — Nike Air Max 90', 'body' => 'Votre devis d\'achat assisté est prêt. Montant : 214.00 USD.', 'action_url' => '/purchase-orders', 'status' => 'sent', 'days_ago' => 2, 'read' => true],
            ['user' => $this->client1, 'type' => 'in_app', 'title' => 'Colis livré', 'body' => 'Votre colis EXP2026030001 a été livré avec succès. Merci d\'avoir choisi Monrespro !', 'action_url' => '/shipments/EXP2026030001', 'status' => 'sent', 'days_ago' => 55, 'read' => true],
            ['user' => $this->client2, 'type' => 'in_app', 'title' => 'Échec de livraison', 'body' => 'La livraison de votre colis EXP2026050009 a échoué. Destinataire absent. Nous reprogrammons.', 'action_url' => '/shipments/EXP2026050009', 'status' => 'sent', 'days_ago' => 3, 'read' => false],
            ['user' => $this->client2, 'type' => 'in_app', 'title' => 'Ticket SAV mis à jour', 'body' => 'Votre ticket TKT-2026-0001 a été mis à jour par l\'opérateur.', 'action_url' => '/sav/TKT-2026-0001', 'status' => 'sent', 'days_ago' => 2, 'read' => false],
            ['user' => $this->client3, 'type' => 'in_app', 'title' => 'Colis réceptionné', 'body' => 'Votre colis a été réceptionné dans notre entrepôt de Bruxelles.', 'action_url' => '/shipments', 'status' => 'sent', 'days_ago' => 1, 'read' => false],
            ['user' => $this->operator, 'type' => 'in_app', 'title' => 'Nouveau ticket SAV urgent', 'body' => 'TKT-2026-0005 — Paiement M-Pesa non pris en compte. Priorité urgente.', 'action_url' => '/sav/TKT-2026-0005', 'status' => 'sent', 'days_ago' => 1, 'read' => false],
            ['user' => $this->driver, 'type' => 'in_app', 'title' => 'Nouveau ramassage assigné', 'body' => 'Un ramassage vous a été assigné : Avenue Kasa-Vubu 120, Barumbu.', 'action_url' => '/pickups', 'status' => 'sent', 'days_ago' => 0, 'read' => false],
            ['user' => $this->adminKin, 'type' => 'in_app', 'title' => 'Ticket escaladé', 'body' => 'Le ticket TKT-2026-0002 (colis endommagé) a été escaladé pour décision.', 'action_url' => '/sav/TKT-2026-0002', 'status' => 'sent', 'days_ago' => 0, 'read' => false],
            ['user' => $this->extraClients['marie.lukusa@monrespro.local'], 'type' => 'in_app', 'title' => 'Pré-alerte confirmée', 'body' => 'Votre pré-alerte ASN-0007 a été enregistrée. Nous attendons votre colis.', 'action_url' => '/pre-alerts', 'status' => 'sent', 'days_ago' => 3, 'read' => true],
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
            ['email' => 'grace.kalala@monrespro.local', 'name' => 'Grace Kalala', 'locale' => 'fr', 'source' => 'registration'],
            ['email' => 'emmanuel.ngoy@monrespro.local', 'name' => 'Emmanuel Ngoy', 'locale' => 'fr', 'source' => 'registration'],
            ['email' => 'cedric.ilunga@monrespro.local', 'name' => 'Cédric Ilunga', 'locale' => 'fr', 'source' => 'registration'],
            ['email' => 'marie.lukusa@monrespro.local', 'name' => 'Marie Lukusa', 'locale' => 'fr', 'source' => 'registration'],
            ['email' => 'nathalie.mwamba@gmail.com', 'name' => 'Nathalie Mwamba', 'locale' => 'fr', 'source' => 'website'],
            ['email' => 'patrick.mukungu@yahoo.fr', 'name' => 'Patrick Mukungu', 'locale' => 'fr', 'source' => 'website'],
            ['email' => 'julien.kasongo@outlook.com', 'name' => 'Julien Kasongo', 'locale' => 'fr', 'source' => 'website'],
            ['email' => 'sandrine.luzolo@gmail.com', 'name' => 'Sandrine Luzolo', 'locale' => 'fr', 'source' => 'facebook'],
            ['email' => 'alain.kimbembe@gmail.com', 'name' => 'Alain Kimbembe', 'locale' => 'fr', 'source' => 'website'],
            ['email' => 'viviane.nzuzi@gmail.com', 'name' => 'Viviane Nzuzi', 'locale' => 'fr', 'source' => 'instagram'],
            ['email' => 'pascal.mwangi@gmail.com', 'name' => 'Pascal Mwangi', 'locale' => 'en', 'source' => 'website'],
            ['email' => 'fatou.diallo@gmail.com', 'name' => 'Fatou Diallo', 'locale' => 'fr', 'source' => 'website'],
            ['email' => 'roger.tshibangu@gmail.com', 'name' => 'Roger Tshibangu', 'locale' => 'fr', 'source' => 'referral'],
            ['email' => 'clemence.mbaya@gmail.com', 'name' => 'Clémence Mbaya', 'locale' => 'fr', 'source' => 'website'],
            ['email' => 'jean.mobutu@gmail.com', 'name' => 'Jean Mobutu', 'locale' => 'fr', 'source' => 'website'],
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
            ['type' => Shipment::class, 'id' => $shipment3?->id, 'user' => $this->customs, 'body' => 'Documents douaniers reçus — manque certificat d\'origine. Contacter l\'expéditeur.', 'internal' => true, 'days_ago' => 4],
            ['type' => Shipment::class, 'id' => $shipment3?->id, 'user' => $this->operator, 'body' => 'Certificat d\'origine demandé au fournisseur chinois. Délai estimé 48h.', 'internal' => true, 'days_ago' => 3],
            ['type' => Shipment::class, 'id' => $shipment9?->id, 'user' => $this->driver, 'body' => 'Première tentative de livraison échouée — personne au domicile. Reprogrammé pour demain.', 'internal' => false, 'days_ago' => 3],
            ['type' => AssistedPurchase::class, 'id' => $ap1?->id, 'user' => $this->operator, 'body' => 'Trottinette reçue au hub. Poids vérifié : 14.5 kg (estimé 15 kg). Légère différence acceptable.', 'internal' => false, 'days_ago' => 1],
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
