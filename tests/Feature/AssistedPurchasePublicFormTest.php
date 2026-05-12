<?php

namespace Tests\Feature;

use App\Enums\AssistedPurchaseStatus;
use App\Jobs\ScrapeAndPersistProductJob;
use App\Jobs\ScrapeProductJob;
use App\Models\Agency;
use App\Models\AssistedPurchase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class AssistedPurchasePublicFormTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;

    protected function setUp(): void
    {
        parent::setUp();

        // Avoid flaky failures: public route uses throttle:10,1 — this suite issues >10 POSTs.
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->agency = Agency::factory()->create([
            'slug' => 'test-agency-'.uniqid(),
            'default_currency' => 'USD',
            'is_active' => true,
        ]);
    }

    /** PUB-001: Successful submission by existing user (email matches a User record). */
    public function test_pub_001_successful_submission_by_existing_user(): void
    {
        Bus::fake();

        $user = User::factory()->create([
            'email' => 'client@test.com',
            'agency_id' => $this->agency->id,
        ]);

        $response = $this->postJson('/api/assisted-purchases/public', [
            'full_name' => 'Jean Dupont',
            'email' => 'client@test.com',
            'phone' => '+243990000000',
            'links' => [
                ['url' => 'https://amazon.com/product/123', 'name' => 'iPhone Case', 'quantity' => 2],
                ['url' => 'https://zara.com/shirt/456', 'quantity' => 1],
            ],
            'note' => 'Taille M pour le t-shirt',
        ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'Demande enregistrée avec succès.')
            ->assertJsonStructure(['message', 'reference']);

        $purchase = AssistedPurchase::query()->latest('id')->first();
        $this->assertNotNull($purchase);
        $this->assertSame($user->id, $purchase->user_id);
        $this->assertSame(AssistedPurchaseStatus::PENDING_QUOTE->value, $purchase->status->value);
        $this->assertSame('AP-'.str_pad((string) $purchase->id, 6, '0', STR_PAD_LEFT), $response->json('reference'));

        $lineNotes = json_decode($purchase->line_notes ?? '{}', true, 512, JSON_THROW_ON_ERROR);
        $this->assertFalse((bool) ($lineNotes['is_prospect'] ?? true));

        $this->assertDatabaseHas('assisted_purchase_items', [
            'assisted_purchase_id' => $purchase->id,
            'url' => 'https://amazon.com/product/123',
            'quantity' => 2,
        ]);

        Bus::assertDispatchedTimes(ScrapeProductJob::class, 2);
        Bus::assertDispatchedTimes(ScrapeAndPersistProductJob::class, 2);
    }

    /** PUB-002: Successful submission by new prospect (email does not match any user). */
    public function test_pub_002_successful_submission_by_new_prospect(): void
    {
        Bus::fake();

        $response = $this->postJson('/api/assisted-purchases/public', [
            'full_name' => 'Nouveau Prospect',
            'email' => 'prospect@newclient.com',
            'phone' => null,
            'links' => [
                ['url' => 'https://example.com/item', 'name' => 'Widget', 'quantity' => 1],
            ],
            'note' => null,
        ]);

        $response->assertCreated()
            ->assertJsonPath(
                'message',
                'Demande enregistrée. Vous recevrez un email de confirmation.',
            );

        $purchase = AssistedPurchase::query()->latest('id')->first();
        $this->assertNotNull($purchase);
        $this->assertNull($purchase->user_id);
        $this->assertSame(AssistedPurchaseStatus::PENDING_QUOTE->value, $purchase->status->value);

        $lineNotes = json_decode($purchase->line_notes ?? '{}', true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue((bool) ($lineNotes['is_prospect'] ?? false));
        $this->assertSame('Nouveau Prospect', $lineNotes['full_name'] ?? null);
        $this->assertSame('prospect@newclient.com', $lineNotes['email'] ?? null);

        Bus::assertDispatchedTimes(ScrapeProductJob::class, 1);
        Bus::assertDispatchedTimes(ScrapeAndPersistProductJob::class, 1);
    }

    /** PUB-003: Multiple links create multiple items. */
    public function test_pub_003_multiple_links_create_multiple_items(): void
    {
        Bus::fake();

        $urls = [
            'https://store.example/a',
            'https://store.example/b',
            'https://store.example/c',
        ];

        $links = [];
        foreach ($urls as $i => $url) {
            $links[] = ['url' => $url, 'name' => 'Item '.($i + 1), 'quantity' => $i + 1];
        }

        $response = $this->postJson('/api/assisted-purchases/public', [
            'full_name' => 'Multi Buyer',
            'email' => 'multi@buyer.test',
            'links' => $links,
        ]);

        $response->assertCreated();

        $purchase = AssistedPurchase::query()->latest('id')->firstOrFail();

        foreach ($urls as $url) {
            $this->assertDatabaseHas('assisted_purchase_items', [
                'assisted_purchase_id' => $purchase->id,
                'url' => $url,
            ]);
        }

        $this->assertSame(3, $purchase->items()->count());

        Bus::assertDispatchedTimes(ScrapeProductJob::class, 3);
        Bus::assertDispatchedTimes(ScrapeAndPersistProductJob::class, 3);
    }

    /** PUB-004: Validation fails if email is missing. */
    public function test_pub_004_validation_fails_when_email_missing(): void
    {
        $response = $this->postJson('/api/assisted-purchases/public', [
            'full_name' => 'Test User',
            'links' => [
                ['url' => 'https://example.com/item', 'quantity' => 1],
            ],
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    /** PUB-005: Validation fails if links is empty. */
    public function test_pub_005_validation_fails_when_links_empty(): void
    {
        $response = $this->postJson('/api/assisted-purchases/public', [
            'full_name' => 'Test User',
            'email' => 'empty-links@test.com',
            'links' => [],
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['links']);
    }

    /** PUB-006: Validation fails if links.*.url is not a valid URL. */
    public function test_pub_006_validation_fails_when_link_url_invalid(): void
    {
        $response = $this->postJson('/api/assisted-purchases/public', [
            'full_name' => 'Test User',
            'email' => 'bad-url@test.com',
            'links' => [
                ['url' => 'not-a-url', 'quantity' => 1],
            ],
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['links.0.url']);
    }

    /** PUB-007: Validation fails if full_name is missing. */
    public function test_pub_007_validation_fails_when_full_name_missing(): void
    {
        $response = $this->postJson('/api/assisted-purchases/public', [
            'email' => 'noname@test.com',
            'links' => [
                ['url' => 'https://example.com/item'],
            ],
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['full_name']);
    }

    /** PUB-008: Scraping jobs are dispatched for each link. */
    public function test_pub_008_scraping_jobs_dispatched_per_link(): void
    {
        Bus::fake();

        $urls = ['https://a.example/p1', 'https://b.example/p2'];

        $this->postJson('/api/assisted-purchases/public', [
            'full_name' => 'Job Check',
            'email' => 'jobs@check.test',
            'links' => [
                ['url' => $urls[0]],
                ['url' => $urls[1]],
            ],
        ])->assertCreated();

        Bus::assertDispatchedTimes(ScrapeProductJob::class, 2);
        Bus::assertDispatchedTimes(ScrapeAndPersistProductJob::class, 2);

        $itemsByUrl = AssistedPurchase::query()->latest('id')->firstOrFail()->items()->get()->keyBy('url');

        foreach ($urls as $url) {
            $itemId = $itemsByUrl[$url]->id;

            Bus::assertDispatched(ScrapeProductJob::class, function (ScrapeProductJob $job) use ($url, $itemId) {
                return $job->url === $url
                    && $job->cacheKey === 'scrape_public_'.$itemId.'_'.md5($url);
            });

            Bus::assertDispatched(ScrapeAndPersistProductJob::class, function (ScrapeAndPersistProductJob $job) use ($itemId) {
                return $job->itemId === $itemId;
            });
        }
    }

    /** PUB-009: Max 10 links per submission. */
    public function test_pub_009_rejects_more_than_ten_links(): void
    {
        $links = [];
        for ($i = 0; $i < 11; $i++) {
            $links[] = ['url' => 'https://example.com/item/'.$i];
        }

        $response = $this->postJson('/api/assisted-purchases/public', [
            'full_name' => 'Too Many',
            'email' => 'many@links.test',
            'links' => $links,
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['links']);
    }

    /** PUB-010: Optional note is stored. */
    public function test_pub_010_optional_note_stored_on_parent(): void
    {
        Bus::fake();

        $note = 'Livrer avant le week-end si possible';

        $this->postJson('/api/assisted-purchases/public', [
            'full_name' => 'Note Tester',
            'email' => 'note@test.com',
            'links' => [
                ['url' => 'https://example.com/note-item'],
            ],
            'note' => $note,
        ])->assertCreated();

        $this->assertDatabaseHas('assisted_purchases', [
            'notes' => $note,
        ]);
    }

    /** PUB-011: Prospect gets confirmation message in the JSON payload. */
    public function test_pub_011_prospect_gets_confirmation_message(): void
    {
        Bus::fake();

        $response = $this->postJson('/api/assisted-purchases/public', [
            'full_name' => 'Prospect Confirmation',
            'email' => 'only-prospect-'.uniqid('', true).'@example.org',
            'links' => [
                ['url' => 'https://example.com/product'],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath(
                'message',
                'Demande enregistrée. Vous recevrez un email de confirmation.',
            );
    }
}
