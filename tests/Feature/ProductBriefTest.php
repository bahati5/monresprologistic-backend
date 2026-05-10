<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductBriefTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_brief_returns_chapters_one_to_three(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('operator');

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/product-brief');

        $response->assertOk()
            ->assertJsonPath('version', '3.0')
            ->assertJsonStructure([
                'chapter_1' => ['title', 'who', 'situation', 'vision', 'objectives'],
                'chapter_2' => ['title', 'problems'],
                'chapter_3' => ['title', 'principles'],
            ]);

        $this->assertNotEmpty($response->json('chapter_3.principles'));
    }
}
