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

        $this->get('/workplans')
            ->assertOk()
            ->assertSee('id="app"', false);

        $this->get('/state-performance/executive')
            ->assertOk()
            ->assertSee('id="app"', false);

        $routerSource = file_get_contents(resource_path('js/spa/router.js'));

        $this->assertNotFalse($routerSource);
        $this->assertLessThan(
            strpos($routerSource, "{ path: '/:pathMatch(.*)*', redirect: '/dashboard' }"),
            strpos($routerSource, "{ path: '/workplans'"),
        );

        $workplanIndexSource = file_get_contents(resource_path('js/spa/views/WorkplanIndexView.vue'));

        $this->assertNotFalse($workplanIndexSource);
        $this->assertStringContainsString('response.data?.data', $workplanIndexSource);
        $this->assertStringNotContainsString('data.id}/edit', $workplanIndexSource);

        $workplanEditorSource = file_get_contents(resource_path('js/spa/views/WorkplanEditorView.vue'));

        $this->assertNotFalse($workplanEditorSource);
        $this->assertStringContainsString("{ id: 'structure'", $workplanEditorSource);
        $this->assertStringContainsString('<AppTabs v-model="tab" :tabs="tabs" />', $workplanEditorSource);
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
