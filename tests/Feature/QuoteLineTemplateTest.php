<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\QuoteLineTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class QuoteLineTemplateTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private Agency $agency;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        Permission::query()->firstOrCreate(
            ['name' => 'assisted_purchase.manage', 'guard_name' => 'web'],
        );

        $this->agency = Agency::factory()->create();
        $this->staff = User::factory()->create(['agency_id' => $this->agency->id]);
        $this->staff->givePermissionTo('assisted_purchase.manage');
    }

    public function test_can_list_quote_line_templates(): void
    {
        QuoteLineTemplate::factory()->count(3)->create(['agency_id' => $this->agency->id]);

        $response = $this->actingAs($this->staff)->getJson('/api/quote-line-templates');

        $response->assertOk()
            ->assertJsonCount(3, 'templates');
    }

    public function test_can_create_quote_line_template(): void
    {
        $response = $this->actingAs($this->staff)->postJson('/api/quote-line-templates', [
            'name' => 'Commission achat',
            'internal_code' => 'COMMISSION',
            'type' => 'percentage',
            'calculation_base' => 'product_price',
            'default_value' => 10,
            'is_mandatory' => true,
            'is_visible_to_client' => true,
            'is_active' => true,
            'display_order' => 1,
            'applies_to' => 'assisted_purchase',
            'behavior' => 'mandatory',
        ]);

        $response->assertCreated()
            ->assertJsonPath('template.internal_code', 'COMMISSION');

        $this->assertDatabaseHas('quote_line_templates', [
            'agency_id' => $this->agency->id,
            'internal_code' => 'COMMISSION',
        ]);
    }

    public function test_enforces_max_20_active_lines(): void
    {
        QuoteLineTemplate::factory()->count(20)->create([
            'agency_id' => $this->agency->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->staff)->postJson('/api/quote-line-templates', [
            'name' => 'Ligne 21',
            'internal_code' => 'LINE_21',
            'type' => 'fixed_amount',
            'default_value' => 5,
            'is_active' => true,
            'behavior' => 'optional',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Limite de 20 lignes actives atteinte.');
    }

    public function test_can_update_quote_line_template(): void
    {
        $template = QuoteLineTemplate::factory()->create([
            'agency_id' => $this->agency->id,
            'name' => 'Old name',
        ]);

        $response = $this->actingAs($this->staff)->putJson("/api/quote-line-templates/{$template->id}", [
            'name' => 'New name',
        ]);

        $response->assertOk()
            ->assertJsonPath('template.name', 'New name');
    }

    public function test_cannot_delete_mandatory_line(): void
    {
        $template = QuoteLineTemplate::factory()->create([
            'agency_id' => $this->agency->id,
            'is_mandatory' => true,
        ]);

        $response = $this->actingAs($this->staff)->deleteJson("/api/quote-line-templates/{$template->id}");

        $response->assertStatus(422);
    }

    public function test_can_delete_optional_line(): void
    {
        $template = QuoteLineTemplate::factory()->create([
            'agency_id' => $this->agency->id,
            'is_mandatory' => false,
        ]);

        $response = $this->actingAs($this->staff)->deleteJson("/api/quote-line-templates/{$template->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('quote_line_templates', ['id' => $template->id]);
    }

    public function test_can_reorder_lines(): void
    {
        $lines = QuoteLineTemplate::factory()->count(3)->create([
            'agency_id' => $this->agency->id,
        ]);

        $response = $this->actingAs($this->staff)->postJson('/api/quote-line-templates/reorder', [
            'order' => [
                ['id' => $lines[2]->id, 'display_order' => 0],
                ['id' => $lines[0]->id, 'display_order' => 1],
                ['id' => $lines[1]->id, 'display_order' => 2],
            ],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('quote_line_templates', ['id' => $lines[2]->id, 'display_order' => 0]);
    }

    public function test_unique_internal_code_per_agency(): void
    {
        QuoteLineTemplate::factory()->create([
            'agency_id' => $this->agency->id,
            'internal_code' => 'DUP_CODE',
        ]);

        $response = $this->actingAs($this->staff)->postJson('/api/quote-line-templates', [
            'name' => 'Duplicate',
            'internal_code' => 'DUP_CODE',
            'type' => 'fixed_amount',
            'default_value' => 5,
            'behavior' => 'optional',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('internal_code');
    }
}
