<?php

namespace App\Support;

use App\Domain\Organization\Models\Mda;
use App\Domain\Organization\Models\PlatformSetting;

class DomainContext
{
    public function __construct(
        protected ?Mda $mda = null,
        protected ?PlatformSetting $platform = null,
    ) {
    }

    public function set(?Mda $mda, PlatformSetting $platform): void
    {
        $this->mda = $mda;
        $this->platform = $platform;
    }

    public function mda(): ?Mda { return $this->mda; }
    public function platform(): ?PlatformSetting { return $this->platform; }
    public function isMdaDomain(): bool { return $this->mda !== null; }

    public function publicProfile(): array
    {
        $setting = $this->mda?->setting;
        $logo = $setting?->logo_path ?? $this->platform?->logo_path ?? 'images/niger-state-logo.jpg';

        return [
            'scope' => $this->isMdaDomain() ? 'mda' : 'platform',
            'state_name' => $this->platform?->state_name ?? 'Niger State',
            'platform_name' => $this->platform?->platform_name ?? 'eHRMIS',
            'name' => $this->mda?->name ?? ($this->platform?->platform_name ?? 'eHRMIS'),
            'acronym' => $setting?->acronym ?? $this->mda?->code ?? ($this->platform?->platform_acronym ?? 'eHRMIS'),
            'logo_url' => asset('storage/'.$logo),
            'vision_html' => $setting?->vision_html,
            'mission_html' => $setting?->mission_html,
            'phone' => $setting?->phone ?? $this->platform?->support_phone,
            'email' => $setting?->email ?? $this->platform?->support_email,
            'mda_id' => $this->mda?->id,
        ];
    }
}
