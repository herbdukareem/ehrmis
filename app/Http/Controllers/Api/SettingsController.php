<?php

namespace App\Http\Controllers\Api;

use App\Domain\Organization\Models\Mda;
use App\Domain\Organization\Models\MdaSetting;
use App\Domain\Organization\Models\PlatformSetting;
use App\Domain\Staff\Models\Rank;
use App\Domain\Staff\Models\Staff;
use App\Http\Controllers\Controller;
use App\Http\Resources\StaffResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class SettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        abort_unless(($request->user()->can('manage-platform-settings') && $request->user()->hasPlatformAccess()) || $request->user()->can('manage-mda-settings'), 403);
        $user = $request->user();
        $mdas = Mda::query()->visibleToUser($user)->with(['setting.headRank', 'setting.headStaff'])->orderBy('name')->get();

        return response()->json(['data' => [
            'platform' => $user->can('manage-platform-settings') && $user->hasPlatformAccess() ? PlatformSetting::query()->first() : null,
            'mdas' => $mdas,
            'ranks' => Rank::query()->orderBy('name')->get(['id', 'name', 'level']),
        ]]);
    }

    public function updatePlatform(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('manage-platform-settings') && $request->user()->hasPlatformAccess(), 403);
        $validated = $request->validate([
            'state_code' => ['required', 'string', 'max:20'],
            'state_name' => ['required', 'string', 'max:255'],
            'platform_name' => ['required', 'string', 'max:255'],
            'platform_acronym' => ['required', 'string', 'max:50'],
            'default_domain' => ['nullable', 'string', 'max:255'],
            'support_email' => ['nullable', 'email'],
            'support_phone' => ['nullable', 'string', 'max:100'],
            'allow_platform_login' => ['required', 'boolean'],
            'logo' => ['nullable', 'image', 'max:4096'],
        ]);
        $setting = PlatformSetting::query()->firstOrCreate(['state_code' => $validated['state_code']]);
        $setting->fill(collect($validated)->except('logo')->all());
        if ($request->hasFile('logo')) {
            $setting->logo_path = $request->file('logo')->store('branding/platform', 'public');
        }
        $setting->save();

        return response()->json(['message' => 'Platform settings updated.', 'data' => $setting]);
    }

    public function updateMda(Request $request, Mda $mda): JsonResponse
    {
        abort_unless($request->user()->can('manage-mda-settings') && $request->user()->canAccessMda($mda->id), 403);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('mdas', 'name')->ignore($mda->id)],
            'code' => ['required', 'string', 'max:50', Rule::unique('mdas', 'code')->ignore($mda->id)],
            'acronym' => ['nullable', 'string', 'max:50'],
            'domain' => ['nullable', 'string', 'max:255', Rule::unique('mda_settings', 'domain')->ignore($mda->setting?->id)],
            'vision_html' => ['nullable', 'string'],
            'mission_html' => ['nullable', 'string'],
            'phone' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email'],
            'head_rank_id' => ['nullable', 'integer', 'exists:ranks,id'],
            'head_staff_id' => ['nullable', 'integer', 'exists:staff,id'],
            'head_title' => ['nullable', 'string', 'max:255'],
            'logo' => ['nullable', 'image', 'max:4096'],
            'signature' => ['nullable', 'image', 'max:4096'],
        ]);
        $this->validateHead($mda, $validated['head_rank_id'] ?? null, $validated['head_staff_id'] ?? null);
        $mda->update(['name' => $validated['name'], 'code' => strtoupper($validated['code'])]);
        $setting = MdaSetting::query()->firstOrNew(['mda_id' => $mda->id]);
        $setting->fill(collect($validated)->except(['name', 'code', 'logo', 'signature'])->map(function ($value, $key) {
            return in_array($key, ['vision_html', 'mission_html'], true) ? $this->sanitizeRichText($value) : $value;
        })->all());
        if ($request->hasFile('logo')) {
            $setting->logo_path = $request->file('logo')->store("branding/mdas/{$mda->id}", 'public');
        }
        if ($request->hasFile('signature')) {
            $setting->signature_path = $request->file('signature')->store("branding/mdas/{$mda->id}/signatures", 'public');
        }
        $setting->save();

        return response()->json(['message' => 'MDA settings updated.', 'data' => $mda->fresh('setting.headStaff')]);
    }

    public function eligibleHeads(Request $request, Mda $mda): JsonResponse
    {
        abort_unless($request->user()->canAccessMda($mda->id), 403);
        $request->validate(['rank_id' => ['required', 'integer', 'exists:ranks,id']]);
        $staff = Staff::query()
            ->where('mda_id', $mda->id)
            ->whereHas('currentEmployment', fn ($query) => $query->where('rank_id', $request->integer('rank_id')))
            ->with(['mda', 'currentEmployment.rank'])
            ->orderBy('full_name')
            ->get();

        return response()->json(['data' => StaffResource::collection($staff)->resolve()]);
    }

    protected function validateHead(Mda $mda, ?int $rankId, ?int $staffId): void
    {
        if (! $staffId) {
            return;
        }
        abort_unless(Staff::query()->whereKey($staffId)->where('mda_id', $mda->id)->whereHas('currentEmployment', fn ($query) => $query->where('rank_id', $rankId))->exists(), 422, 'The selected head must belong to this MDA and hold the selected rank.');
    }

    protected function sanitizeRichText(?string $value): ?string
    {
        return $value === null ? null : strip_tags($value, '<p><br><strong><b><em><i><u><ul><ol><li><a><h2><h3>');
    }
}
