<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\QuoteEmailTemplate;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class QuoteSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private Agency $agency;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::factory()->create();
        $this->staff = User::factory()->create(['agency_id' => $this->agency->id]);
        Permission::query()->firstOrCreate(
            ['name' => 'assisted_purchase.manage', 'guard_name' => 'web'],
        );
        $this->staff->givePermissionTo('assisted_purchase.manage');
    }

    public function test_can_read_currency_settings(): void
    {
        Setting::setValue('quote_primary_currency', 'USD');
        Setting::setValue('quote_secondary_currency_enabled', '1');
        Setting::setValue('quote_secondary_currency', 'CDF');
        Setting::setValue('quote_secondary_currency_rate', '2800');

        $response = $this->actingAs($this->staff)->getJson('/api/settings/quote-currency');

        $response->assertOk()
            ->assertJsonPath('primary_currency', 'USD')
            ->assertJsonPath('secondary_currency_enabled', true)
            ->assertJsonPath('rate', 2800)
            ->assertJsonPath('scraped_price_to_primary_multiplier', 1);
    }

    public function test_can_update_currency_settings(): void
    {
        $response = $this->actingAs($this->staff)->putJson('/api/settings/quote-currency', [
            'primary_currency' => 'EUR',
            'secondary_currency_enabled' => true,
            'secondary_currency' => 'CDF',
            'rate_mode' => 'manual',
            'rate' => 3200,
            'scraped_price_to_primary_multiplier' => 1.15,
        ]);

        $response->assertOk();
        $this->assertEquals('EUR', Setting::getValue('quote_primary_currency'));
        $this->assertEquals('3200', Setting::getValue('quote_secondary_currency_rate'));
        $this->assertEquals('1.15', Setting::getValue('quote_scraped_price_to_primary_multiplier'));
    }

    public function test_can_read_follow_up_settings(): void
    {
        Setting::setValue('quote_validity_days', '7');
        Setting::setValue('quote_auto_reminders_enabled', '1');

        $response = $this->actingAs($this->staff)->getJson('/api/settings/quote-follow-up');

        $response->assertOk()
            ->assertJsonPath('validity_days', 7)
            ->assertJsonPath('auto_reminders_enabled', true);
    }

    public function test_can_update_follow_up_settings(): void
    {
        $response = $this->actingAs($this->staff)->putJson('/api/settings/quote-follow-up', [
            'validity_days' => 14,
            'reminder_1_delay_days' => 3,
            'reminder_2_delay_days' => 7,
            'auto_reminders_enabled' => false,
        ]);

        $response->assertOk();
        $this->assertEquals('14', Setting::getValue('quote_validity_days'));
        $this->assertEquals('0', Setting::getValue('quote_auto_reminders_enabled'));
    }

    public function test_can_list_email_templates(): void
    {
        QuoteEmailTemplate::create([
            'agency_id' => $this->agency->id,
            'event' => 'quote_sent',
            'subject' => 'Test subject',
            'body' => 'Test body',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->staff)->getJson('/api/settings/quote-email-templates');

        $response->assertOk()
            ->assertJsonCount(1, 'templates');
    }

    public function test_can_update_email_template(): void
    {
        $template = QuoteEmailTemplate::create([
            'agency_id' => $this->agency->id,
            'event' => 'reminder_1',
            'subject' => 'Old subject',
            'body' => 'Old body',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->staff)->patchJson("/api/settings/quote-email-templates/{$template->uuid}", [
            'subject' => 'New subject',
            'body' => 'New body with {{client_name}}',
        ]);

        $response->assertOk()
            ->assertJsonPath('template.subject', 'New subject');
    }

    public function test_can_read_audit_log(): void
    {
        $response = $this->actingAs($this->staff)->getJson('/api/settings/quote-templates/audit-log');

        $response->assertOk()
            ->assertJsonStructure(['entries']);
    }
}
