<?php

namespace App\Domain\Organization\Services;

use App\Domain\Organization\Models\Mda;

class OfficialSignatoryService
{
    public function forMda(Mda $mda): array
    {
        $setting = $mda->loadMissing(['setting.headStaff.currentEmployment.rank'])->setting;

        return [
            'name' => $setting?->headStaff?->full_name,
            'staff_id' => $setting?->head_staff_id,
            'rank' => $setting?->headStaff?->currentEmployment?->rank?->name,
            'title' => $setting?->head_title,
            'signature_url' => $setting?->signature_path ? asset('storage/'.$setting->signature_path) : null,
        ];
    }
}
