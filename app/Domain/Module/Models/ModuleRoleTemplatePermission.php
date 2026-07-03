<?php

namespace App\Domain\Module\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModuleRoleTemplatePermission extends Model
{
    protected $fillable = [
        'module_role_template_id',
        'permission_name',
    ];

    public function roleTemplate(): BelongsTo
    {
        return $this->belongsTo(ModuleRoleTemplate::class, 'module_role_template_id');
    }
}
