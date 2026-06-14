<?php

namespace App\Http\Middleware;

use App\Domain\Organization\Models\Mda;
use App\Domain\Organization\Models\PlatformSetting;
use App\Support\DomainContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveDomainContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = strtolower($request->getHost());
        $platform = PlatformSetting::query()->firstOrCreate(
            ['state_code' => 'NG-NI'],
            [
                'state_name' => 'Niger State',
                'platform_name' => 'eHRMIS',
                'platform_acronym' => 'eHRMIS',
                'logo_path' => 'images/niger-state-logo.jpg',
                'allow_platform_login' => true,
            ],
        );
        $mda = Mda::query()->with('setting')->whereHas('setting', fn ($query) => $query->where('domain', $host))->first();

        app(DomainContext::class)->set($mda, $platform);

        return $next($request);
    }
}
