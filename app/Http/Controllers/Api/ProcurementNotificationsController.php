<?php

namespace App\Http\Controllers\Api;

use App\Domain\Module\Services\ModuleAccessService;
use App\Domain\Organization\Models\Mda;
use App\Domain\Procurement\Models\ProcurementAlertRead;
use App\Domain\Procurement\Services\ProcurementAlertService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class ProcurementNotificationsController extends BudgetProcurementController
{
    public function index(Request $request, ProcurementAlertService $service)
    {
        [$mdas, $mdaId, $filters] = $this->context($request);
        $feed = $service->feed($request->user(), $mdaId);
        $filtered = $this->filter($feed, $filters);
        $page = (int) ($filters['page'] ?? 1);
        $limit = (int) ($filters['per_page'] ?? 25);

        return $this->result(['mda_id' => $mdaId, 'mdas' => $mdas, 'categories' => ProcurementAlertService::CATEGORIES,
            'unread_count' => $feed->where('unread', true)->count(), 'total_count' => $feed->count(),
            'filtered_unread_count' => $filtered->where('unread', true)->count(),
            'rows' => $filtered->forPage($page, $limit)->values(), 'counts' => $feed->countBy('category'),
            'meta' => ['current_page' => $page, 'last_page' => max(1, (int) ceil($filtered->count() / $limit)), 'total' => $filtered->count(), 'per_page' => $limit]]);
    }

    public function summary(Request $request, ProcurementAlertService $service)
    {
        [, $mdaId] = $this->context($request);
        $feed = $service->feed($request->user(), $mdaId, false);

        return $this->result(['mda_id' => $mdaId, 'unread_count' => $feed->where('unread', true)->count(), 'total_count' => $feed->count()]);
    }

    public function read(Request $request, ProcurementAlertService $service)
    {
        [, $mdaId] = $this->context($request);
        $data = $request->validate(['keys' => ['required', 'array', 'min:1', 'max:100'], 'keys.*' => ['required', 'string', 'size:64', 'distinct', 'regex:/^[a-f0-9]+$/'], 'read' => ['required', 'boolean']]);
        $visible = $service->feed($request->user(), $mdaId)->pluck('id');
        abort_unless(collect($data['keys'])->diff($visible)->isEmpty(), 404);
        $this->saveReads($request, $mdaId, $data['keys'], (bool) $data['read']);

        return $this->result(['updated' => count($data['keys'])]);
    }

    public function readAll(Request $request, ProcurementAlertService $service)
    {
        [, $mdaId, $filters] = $this->context($request);
        $keys = $this->filter($service->feed($request->user(), $mdaId), $filters)->where('unread', true)->pluck('id')->all();
        $this->saveReads($request, $mdaId, $keys, true);

        return $this->result(['updated' => count($keys)]);
    }

    private function context(Request $request): array
    {
        abort_unless($request->user()->canAny(ProcurementAlertService::READ_PERMISSIONS), 403);
        $filters = $request->validate(['mda_id' => ['nullable', 'integer', 'min:1'],
            'category' => ['nullable', Rule::in(array_keys(ProcurementAlertService::CATEGORIES))],
            'unread' => ['nullable', 'boolean'], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $modules = app(ModuleAccessService::class);
        $mdas = Mda::query()->visibleToUser($request->user())->orderBy('name')->get(['id', 'name', 'code'])
            ->filter(fn ($mda) => $modules->mdaHasModule($mda->id, 'budget_procurement'))->values();
        $mdaId = (int) ($filters['mda_id'] ?? $mdas->first()?->id);
        abort_unless($mdas->contains('id', $mdaId), 403);
        $this->assertMda($request, $mdaId);

        return [$mdas, $mdaId, $filters];
    }

    private function filter(Collection $feed, array $filters): Collection
    {
        if (! empty($filters['category'])) {
            $feed = $feed->where('category', $filters['category']);
        }
        if (! empty($filters['unread'])) {
            $feed = $feed->where('unread', true);
        }

        return $feed->values();
    }

    private function saveReads(Request $request, int $mdaId, array $keys, bool $read): void
    {
        foreach (array_chunk($keys, 200) as $chunk) {
            if ($read) {
                ProcurementAlertRead::query()->upsert(array_map(fn ($key) => ['user_id' => $request->user()->id, 'mda_id' => $mdaId, 'alert_key' => $key, 'read_at' => now()], $chunk), ['mda_id', 'user_id', 'alert_key'], ['read_at']);
            } else {
                ProcurementAlertRead::query()->where('mda_id', $mdaId)->where('user_id', $request->user()->id)->whereIn('alert_key', $chunk)->delete();
            }
        }
    }
}
