<?php

namespace App\Domain\Organization\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformSetting extends Model
{
    protected $fillable = [
        'state_code', 'state_name', 'platform_name', 'platform_acronym', 'default_domain',
        'logo_path', 'support_email', 'support_phone', 'allow_platform_login',
    ];

    protected function casts(): array
    {
        return ['allow_platform_login' => 'boolean'];
    }
}
