<?php

namespace Tests\Feature;

use App\Enums\AssistedPurchaseStatus;
use App\Mail\QuoteExpiredMail;
use App\Models\Agency;
use App\Models\AssistedPurchase;
use App\Models\QuoteSnapshot;
use App\Models\Setting;
use App\Models\User;
use App\Services\QuoteFollowUpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Quote follow-up workflow (WF-001 to WF-007).
 *
 * Covers {@see QuoteFollowUpService} reminder / expiry behaviour and {@see \App\Http\Controllers\QuoteResponseController}
 * accepting or refusing quotes (API).
 */
class QuoteWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function createAgencyAndClient(): array
    {
        $agency = Agency::factory()->create();

        return [$agency, User::factory()->create(['agency_id' => $agency->id])];
    }

    private function assertDefaultReminderSettingsApplied(): void
    {
        $this->assertSame('7', Setting::getValue('quote_validity_days', 'MISSING'));
        $this->assertSame('2', Setting::getValue('quote_reminder_1_delay_days', 'MISSING'));
        $this->assertSame('5', Setting::getValue('quote_reminder_2_delay_days', 'MISSING'));
        $this->assertSame('1', Setting::getValue('quote_auto_reminders_enabled', 'MISSING'));
    }

    /**
     * WF-001 : shouldSendReminder returns 1 once quoted_at is at least reminder_1_delay_days ago (defaults: 2).
     */
    public function test_wf_001_should_send_reminder_returns_1_after_first_delay(): void
    {
        [, $client] = $this->createAgencyAndClient();
        $service = app(QuoteFollowUpService::class);

        $this->travelTo(Carbon::parse('2026-05-15 12:00:00', 'UTC'));
        $this->assertDefaultReminderSettingsApplied();

        $purchase = AssistedPurchase::create([
            'user_id' => $client->id,
            'status' => AssistedPurchaseStatus::QUOTED,
            'product_url' => 'https://example.com/p',
            'article_label' => 'Item',
            'quantity' => 1,
            'quoted_at' => Carbon::parse('2026-05-13 12:00:00', 'UTC'),
            'reminder_count' => 0,
            'quote_expires_at' => Carbon::parse('2026-05-22 12:00:00', 'UTC'),
            'total_amount' => 100.00,
        ]);

        $this->assertSame(1, $service->shouldSendReminder($purchase->fresh()));

        $this->travelBack();
    }

    /**
     * WF-002 : After reminder #1 was sent (reminder_count = 1), shouldSendReminder returns 2 once
     * quoted_at is at least reminder_2_delay_days ago (defaults: 5).
     */
    public function test_wf_002_should_send_reminder_returns_2_after_second_delay(): void
    {
        [, $client] = $this->createAgencyAndClient();
        $service = app(QuoteFollowUpService::class);

        $this->travelTo(Carbon::parse('2026-05-15 12:00:00', 'UTC'));
        $this->assertDefaultReminderSettingsApplied();

        $purchase = AssistedPurchase::create([
            'user_id' => $client->id,
            'status' => AssistedPurchaseStatus::QUOTED,
            'product_url' => 'https://example.com/p',
            'article_label' => 'Item',
            'quantity' => 1,
            'quoted_at' => Carbon::parse('2026-05-08 12:00:00', 'UTC'),
            'reminder_count' => 1,
            'quote_expires_at' => Carbon::parse('2026-05-22 12:00:00', 'UTC'),
            'total_amount' => 100.00,
        ]);

        $this->assertSame(2, $service->shouldSendReminder($purchase->fresh()));

        $this->travelBack();
    }

    /**
     * WF-003 : When quote_auto_reminders_enabled is off, shouldSendReminder returns null.
     */
    public function test_wf_003_should_send_reminder_null_when_auto_reminders_disabled(): void
    {
        [, $client] = $this->createAgencyAndClient();
        $service = app(QuoteFollowUpService::class);

        Setting::setValue('quote_auto_reminders_enabled', '0');

        $this->travelTo(Carbon::parse('2026-05-15 12:00:00', 'UTC'));

        $purchase = AssistedPurchase::create([
            'user_id' => $client->id,
            'status' => AssistedPurchaseStatus::QUOTED,
            'product_url' => 'https://example.com/p',
            'article_label' => 'Item',
            'quantity' => 1,
            'quoted_at' => Carbon::parse('2026-05-01 12:00:00', 'UTC'),
            'reminder_count' => 0,
            'quote_expires_at' => Carbon::parse('2026-05-22 12:00:00', 'UTC'),
            'total_amount' => 100.00,
        ]);

        $this->assertNull($service->shouldSendReminder($purchase->fresh()));

        $this->travelBack();
    }

    /**
     * WF-004 : shouldExpire is true when quote_expires_at is in the past (status QUOTED).
     */
    public function test_wf_004_should_expire_true_when_quote_expires_at_past(): void
    {
        [, $client] = $this->createAgencyAndClient();
        $service = app(QuoteFollowUpService::class);

        $this->travelTo(Carbon::parse('2026-05-15 12:00:00', 'UTC'));

        $purchase = AssistedPurchase::create([
            'user_id' => $client->id,
            'status' => AssistedPurchaseStatus::QUOTED,
            'product_url' => 'https://example.com/p',
            'article_label' => 'Item',
            'quantity' => 1,
            'quoted_at' => Carbon::parse('2026-05-01 12:00:00', 'UTC'),
            'quote_expires_at' => Carbon::parse('2026-05-14 12:00:00', 'UTC'),
            'total_amount' => 100.00,
        ]);

        $this->assertTrue($service->shouldExpire($purchase->fresh()));

        $this->travelBack();
    }

    /**
     * WF-005 : expireQuote sets purchase status to EXPIRED and notifies the client by email.
     */
    public function test_wf_005_expire_quote_sets_status_expired(): void
    {
        Mail::fake();

        [, $client] = $this->createAgencyAndClient();
        $service = app(QuoteFollowUpService::class);

        $purchase = AssistedPurchase::create([
            'user_id' => $client->id,
            'status' => AssistedPurchaseStatus::QUOTED,
            'product_url' => 'https://example.com/p',
            'article_label' => 'Item',
            'quantity' => 1,
            'quoted_at' => now(),
            'quote_expires_at' => now()->subDay(),
            'total_amount' => 199.99,
            'reminder_count' => 0,
        ]);

        $snapshot = QuoteSnapshot::create([
            'assisted_purchase_id' => $purchase->id,
            'version' => 1,
            'snapshot_data' => ['total_primary' => 199.99],
            'articles_data' => [['name' => 'A', 'quantity' => 1]],
            'total_primary' => 199.99,
            'primary_currency' => 'USD',
            'sent_at' => now(),
            'expires_at' => now()->subDay(),
            'response_token' => 'wf005_token_'.bin2hex(random_bytes(8)),
            'client_response' => 'pending',
        ]);

        $service->expireQuote($purchase->fresh());

        $purchase->refresh();
        $this->assertSame(AssistedPurchaseStatus::EXPIRED, $purchase->status);

        $snapshot->refresh();
        $this->assertSame('expired', $snapshot->client_response);

        Mail::assertSent(QuoteExpiredMail::class, function (QuoteExpiredMail $mail) use ($purchase) {
            return $mail->purchase->is($purchase);
        });
    }

    /**
     * WF-006 : Accepting a quote via API stops automated reminders by setting reminder_count to 99.
     */
    public function test_wf_006_accepting_quote_sets_reminder_count_to_99(): void
    {
        [, $client] = $this->createAgencyAndClient();

        $purchase = AssistedPurchase::create([
            'user_id' => $client->id,
            'status' => AssistedPurchaseStatus::QUOTED,
            'product_url' => 'https://example.com/p',
            'article_label' => 'Item',
            'quantity' => 1,
            'quoted_at' => now(),
            'quote_expires_at' => now()->addWeek(),
            'total_amount' => 150.00,
            'reminder_count' => 0,
        ]);

        QuoteSnapshot::create([
            'assisted_purchase_id' => $purchase->id,
            'version' => 1,
            'snapshot_data' => ['total_primary' => 150],
            'articles_data' => [['name' => 'A', 'quantity' => 1]],
            'total_primary' => 150.00,
            'primary_currency' => 'USD',
            'sent_at' => now(),
            'expires_at' => now()->addWeek(),
            'response_token' => 'wf006_accept_token',
            'client_response' => 'pending',
        ]);

        $response = $this->postJson('/api/quotes/respond', [
            'token' => 'wf006_accept_token',
            'response' => 'accepted',
        ]);

        $response->assertOk()
            ->assertJsonPath('response', 'accepted');

        $purchase->refresh();
        $this->assertSame(AssistedPurchaseStatus::AWAITING_PAYMENT, $purchase->status);
        $this->assertSame(99, $purchase->reminder_count);
    }

    /**
     * WF-007 : Refusing a quote via API stops automated reminders by setting reminder_count to 99.
     */
    public function test_wf_007_refusing_quote_sets_reminder_count_to_99(): void
    {
        [, $client] = $this->createAgencyAndClient();

        $purchase = AssistedPurchase::create([
            'user_id' => $client->id,
            'status' => AssistedPurchaseStatus::QUOTED,
            'product_url' => 'https://example.com/p',
            'article_label' => 'Item',
            'quantity' => 1,
            'quoted_at' => now(),
            'quote_expires_at' => now()->addWeek(),
            'total_amount' => 175.50,
            'reminder_count' => 1,
        ]);

        QuoteSnapshot::create([
            'assisted_purchase_id' => $purchase->id,
            'version' => 1,
            'snapshot_data' => ['total_primary' => 175.50],
            'articles_data' => [['name' => 'A', 'quantity' => 1]],
            'total_primary' => 175.50,
            'primary_currency' => 'USD',
            'sent_at' => now(),
            'expires_at' => now()->addWeek(),
            'response_token' => 'wf007_refuse_token',
            'client_response' => 'pending',
        ]);

        $response = $this->postJson('/api/quotes/respond', [
            'token' => 'wf007_refuse_token',
            'response' => 'refused',
            'refusal_reason' => 'too_expensive',
            'refusal_note' => 'Beyond budget.',
        ]);

        $response->assertOk()
            ->assertJsonPath('response', 'refused');

        $purchase->refresh();
        $this->assertSame(AssistedPurchaseStatus::CANCELLED, $purchase->status);
        $this->assertSame(99, $purchase->reminder_count);
    }
}
