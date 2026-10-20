<?php

namespace App\Http\Controllers\Api;

use App\Domain\Module\Services\ModuleAccessService;
use App\Domain\Organization\Models\Mda;
use App\Domain\Procurement\Services\ProcurementWorkflowService;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\ValidationException;

abstract class BudgetProcurementController extends Controller
{
    protected function assertMda(Request $request, int $mdaId): void
    {
        abort_unless($request->user()->canAccessMda($mdaId), 403);
        abort_unless(app(ModuleAccessService::class)->userCanAccessModule($request->user(), 'budget_procurement', $mdaId), 403);
    }

    protected function listing(Request $request, Builder $query, array $filters = []): JsonResponse
    {
        $data = $request->validate([
            'mda_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'string', 'max:40'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'search' => ['nullable', 'string', 'max:255'],
            ...$filters,
        ]);

        if (! empty($data['mda_id'])) {
            $this->assertMda($request, (int) $data['mda_id']);
            $query->where('mda_id', $data['mda_id']);
        } else {
            $modules = app(ModuleAccessService::class);
            $mdaIds = Mda::query()->visibleToUser($request->user())->pluck('id')
                ->filter(fn ($id): bool => $modules->mdaHasModule((int) $id, 'budget_procurement'));
            $query->whereIn('mda_id', $mdaIds);
        }

        foreach ($data as $key => $value) {
            if (! in_array($key, ['mda_id', 'per_page', 'search'], true) && $value !== null && $value !== '') {
                $query->where($key, $value);
            }
        }

        if (! empty($data['search'])) {
            $columns = array_intersect($query->getModel()->getFillable(), [
                'name', 'title', 'description', 'code', 'requisition_no', 'vendor_no',
                'solicitation_no', 'award_no', 'po_no', 'receipt_no', 'commitment_no', 'invoice_no', 'payment_no', 'payment_reference',
            ]);
            $query->where(function (Builder $search) use ($columns, $data): void {
                foreach ($columns as $column) {
                    $search->orWhere($column, 'like', '%'.$data['search'].'%');
                }
            });
        }

        return response()->json($query->latest('id')->paginate($data['per_page'] ?? 25));
    }

    /** Related IDs must belong to the target MDA, including for users with access to multiple MDAs. */
    protected function reference(string $model, int $mdaId, array $constraints = []): Exists
    {
        $rule = Rule::exists((new $model)->getTable(), 'id')->where('mda_id', $mdaId);
        foreach ($constraints as $column => $value) {
            $rule->where($column, $value);
        }

        return $rule;
    }

    protected function uniqueWithinMda(string $table, string $column, int $mdaId): Unique
    {
        return Rule::unique($table, $column)->where('mda_id', $mdaId);
    }

    protected function selectedMda(Request $request): int
    {
        $data = $request->validate(['mda_id' => ['required', 'integer', 'exists:mdas,id']]);
        $this->assertMda($request, (int) $data['mda_id']);

        return (int) $data['mda_id'];
    }

    protected function result(mixed $data, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data], $status);
    }

    protected function procurementDetail(Model $record): JsonResponse
    {
        $record->setAttribute('history', app(ProcurementWorkflowService::class)->history($record));

        return $this->result($record);
    }

    protected function assertEditable(Model $model, array $statuses = ['draft']): void
    {
        if (! in_array($model->status, $statuses, true)) {
            throw ValidationException::withMessages(['status' => 'This record cannot be changed at its current workflow stage.']);
        }
    }

    protected function assertCycleWritable(?Model $cycle): void
    {
        if ($cycle && (in_array($cycle->status, ['closed', 'locked'], true) || $cycle->closed_at || $cycle->locked_at)) {
            throw ValidationException::withMessages(['budget_cycle_id' => 'This budget cycle is closed or locked.']);
        }
    }
}
