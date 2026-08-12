<?php

namespace Tests\Feature\Auth;

use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SanctumStatefulDomainsTest extends TestCase
{
    public function test_fallback_config_includes_the_app_host_and_current_request_placeholder(): void
    {
        $statefulDomains = $this->loadStatefulDomainsFromConfig(null);
        $appHost = parse_url(config('app.url'), PHP_URL_HOST);

        $this->assertIsString($appHost);
        $this->assertContains($appHost, $statefulDomains);
        $this->assertContains(Sanctum::$currentRequestHostPlaceholder, $statefulDomains);
    }

    public function test_current_request_host_is_treated_as_stateful_for_mda_domains(): void
    {
        config()->set('sanctum.stateful', [Sanctum::$currentRequestHostPlaceholder]);

        $request = Request::create(
            'http://moh.example.test/api/login',
            'POST',
            [],
            [],
            [],
            [
                'HTTP_HOST' => 'moh.example.test',
                'HTTP_REFERER' => 'http://moh.example.test/login',
            ],
        );

        $this->assertTrue(EnsureFrontendRequestsAreStateful::fromFrontend($request));
    }

    private function loadStatefulDomainsFromConfig(?string $configuredDomains): array
    {
        $original = env('SANCTUM_STATEFUL_DOMAINS');

        if ($configuredDomains === null) {
            putenv('SANCTUM_STATEFUL_DOMAINS');
            unset($_ENV['SANCTUM_STATEFUL_DOMAINS'], $_SERVER['SANCTUM_STATEFUL_DOMAINS']);
        } else {
            putenv("SANCTUM_STATEFUL_DOMAINS={$configuredDomains}");
            $_ENV['SANCTUM_STATEFUL_DOMAINS'] = $configuredDomains;
            $_SERVER['SANCTUM_STATEFUL_DOMAINS'] = $configuredDomains;
        }

        try {
            return (require config_path('sanctum.php'))['stateful'];
        } finally {
            if ($original === null || $original === false) {
                putenv('SANCTUM_STATEFUL_DOMAINS');
                unset($_ENV['SANCTUM_STATEFUL_DOMAINS'], $_SERVER['SANCTUM_STATEFUL_DOMAINS']);
            } else {
                putenv("SANCTUM_STATEFUL_DOMAINS={$original}");
                $_ENV['SANCTUM_STATEFUL_DOMAINS'] = $original;
                $_SERVER['SANCTUM_STATEFUL_DOMAINS'] = $original;
            }
        }
    }
}
