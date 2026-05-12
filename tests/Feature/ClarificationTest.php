<?php

namespace Tests\Feature;

use App\Enums\AssistedPurchaseStatus;
use App\Models\Agency;
use App\Models\AssistedPurchase;
use App\Models\User;
use App\Services\Twilio\TwilioGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Demandes de clarification client (POST /api/assisted-purchases/{id}/clarification).
 * CLR-001 à CLR-004.
 */
class ClarificationTest extends TestCase
{
    use RefreshDatabase;

    protected Agency $agency;

    protected User $client;

    protected User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::create([
            'name' => 'manage_assisted_purchases',
            'guard_name' => 'web',
        ]);

        $roleClient = Role::create(['name' => 'client', 'guard_name' => 'web']);
        $roleOperator = Role::create(['name' => 'operator', 'guard_name' => 'web']);
        $roleOperator->givePermissionTo('manage_assisted_purchases');

        $this->agency = Agency::factory()->create();

        $this->client = User::factory()->create([
            'agency_id' => $this->agency->id,
            'email' => 'client-clarification@example.com',
            'phone' => '+15551234567',
        ]);
        $this->client->assignRole($roleClient);

        $this->staff = User::factory()->create([
            'agency_id' => $this->agency->id,
        ]);
        $this->staff->assignRole($roleOperator);
        $this->staff->givePermissionTo('manage_assisted_purchases');
    }

    protected function seedPurchase(): AssistedPurchase
    {
        return AssistedPurchase::query()->create([
            'user_id' => $this->client->id,
            'status' => AssistedPurchaseStatus::PENDING_QUOTE,
            'product_url' => 'https://example.com/p',
            'article_label' => 'Article test',
            'quantity' => 1,
        ]);
    }

    protected function clarificationMessage(): string
    {
        return 'Pourriez-vous préciser la taille et la couleur souhaitées pour cette commande ?';
    }

    /** CLR-001 : le staff peut envoyer une clarification via le canal email. */
    public function test_clr_001_staff_can_send_clarification_via_email_channel(): void
    {
        Event::fake([MessageSent::class]);

        $purchase = $this->seedPurchase();

        Sanctum::actingAs($this->staff);

        $response = $this->postJson(
            "/api/assisted-purchases/{$purchase->id}/clarification",
            [
                'message' => $this->clarificationMessage(),
                'channels' => ['email'],
            ]
        );

        $response->assertOk()
            ->assertJsonPath('message', 'Demande de clarification envoyée.');

        Event::assertDispatched(MessageSent::class);
    }

    /** CLR-002 : le staff peut envoyer une clarification via le canal SMS. */
    public function test_clr_002_staff_can_send_clarification_via_sms_channel(): void
    {
        $purchase = $this->seedPurchase();

        $this->mock(TwilioGateway::class, function ($mock) {
            $mock->shouldReceive('sendSms')->once()->andReturnUndefined();
        });

        Sanctum::actingAs($this->staff);

        $response = $this->postJson(
            "/api/assisted-purchases/{$purchase->id}/clarification",
            [
                'message' => $this->clarificationMessage(),
                'channels' => ['sms'],
            ]
        );

        $response->assertOk();
    }

    /** CLR-003 : validation — message requis. */
    public function test_clr_003_validation_message_is_required(): void
    {
        $purchase = $this->seedPurchase();

        Sanctum::actingAs($this->staff);

        $response = $this->postJson(
            "/api/assisted-purchases/{$purchase->id}/clarification",
            [
                'channels' => ['email'],
            ]
        );

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['message']);
    }

    /** CLR-004 : mise à jour du dossier (clarification_message, clarification_sent_at). */
    public function test_clr_004_clarification_updates_purchase_record(): void
    {
        Mail::fake();

        $purchase = $this->seedPurchase();
        $msg = $this->clarificationMessage();

        Sanctum::actingAs($this->staff);

        $before = now();

        $this->postJson(
            "/api/assisted-purchases/{$purchase->id}/clarification",
            [
                'message' => $msg,
                'channels' => ['email'],
            ]
        )->assertOk();

        $purchase->refresh();

        $this->assertSame($msg, $purchase->clarification_message);
        $this->assertNotNull($purchase->clarification_sent_at);
        $this->assertGreaterThanOrEqual($before->timestamp, $purchase->clarification_sent_at->timestamp);
    }
}
