<?php

namespace App\Http\Requests\Module;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMdaModulesRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return (bool) $user
            && $user->hasPlatformAccess()
            && $user->can('manage-platform-settings')
            && $user->hasAnyRole(['Super Admin', 'Platform Admin', 'MIS Admin']);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'modules' => ['required', 'array'],
            'modules.*.code' => ['required', 'string', 'exists:modules,code'],
            'modules.*.enabled' => ['required', 'boolean'],
        ];
    }
}
