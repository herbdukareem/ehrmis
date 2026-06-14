<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SpaApplicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_browser_routes_mount_the_spa(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('id="app"', false)
            ->assertDontSee('data-page=', false);

        $this->get('/staff/42')
            ->assertOk()
            ->assertSee('id="app"', false);
    }

    public function test_api_requires_session_authentication(): void
    {
        $this->getJson('/api/dashboard')->assertUnauthorized();

        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.email', $user->email);
    }
}
