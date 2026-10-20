<?php

namespace App\Http\Controllers\Api;

use App\Domain\Budget\Models\BudgetAccount;
use App\Domain\Budget\Models\BudgetAllocation;
use App\Domain\Budget\Models\BudgetCycle;
use App\Domain\Module\Services\ModuleAccessService;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Mda;
use App\Domain\Procurement\Models\ProcurementPlan;
use App\Domain\Procurement\Models\ProcurementRequisition;
use App\Domain\Procurement\Models\PurchaseOrder;
use App\Domain\Procurement\Models\SupplierInvoice;
use App\Domain\Procurement\Models\Vendor;
use App\Domain\Workplan\Models\Workplan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class BudgetProcurementOptionsController extends BudgetProcurementController
{
    public function __invoke(Request $request, ModuleAccessService $modules): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('view-budget-cycles') || $user->can('view-procurement') || $user->can('view-supplier-payables'), 403);
        $data = $request->validate(['mda_id' => ['nullable', 'integer', 'min:1']]);
        $mdas = Mda::query()->visibleToUser($user)->orderBy('name')->get(['id', 'code', 'name'])
            ->filter(fn ($mda): bool => $modules->mdaHasModule($mda->id, 'budget_procurement'))->values();
        $mdaId = (int) ($data['mda_id'] ?? $mdas->first()?->id);
        abort_unless($mdas->contains('id', $mdaId), 403);
        $this->assertMda($request, $mdaId);
        $budget = $user->can('view-budget-cycles');
        $procurement = $user->can('view-procurement');
        $payables = $user->can('view-supplier-payables');
        $departments = Department::query()->where('mda_id', $mdaId);
        if ($user->hasDepartmentRestrictedAccess()) {
            $user->scopeToAccessibleDepartments($departments, 'id');
        }

        return $this->result([
            'payment_request_key' => $payables ? (string) Str::uuid() : null,
            'mda_id' => $mdaId,
            'mdas' => $mdas,
            'departments' => $departments->orderBy('name')->get(['id', 'name']),
            'cycles' => $budget ? BudgetCycle::query()->where('mda_id', $mdaId)->latest('year')->get(['id', 'year', 'name', 'status']) : [],
            'accounts' => $budget ? BudgetAccount::query()->where('mda_id', $mdaId)->where('status', 'active')->orderBy('code')->get(['id', 'code', 'name', 'account_type']) : [],
            'allocations' => $budget ? BudgetAllocation::query()->where('mda_id', $mdaId)->where('status', 'approved')->get(['id', 'budget_cycle_id', 'title', 'approved_amount']) : [],
            'plans' => $procurement ? ProcurementPlan::query()->where('mda_id', $mdaId)->latest('id')->get(['id', 'title', 'year', 'budget_cycle_id', 'status']) : [],
            'requisitions' => $procurement ? ProcurementRequisition::query()->where('mda_id', $mdaId)->where('status', 'approved')->latest('id')->get(['id', 'requisition_no', 'title', 'budget_cycle_id']) : [],
            'vendors' => $procurement ? Vendor::query()->where('mda_id', $mdaId)->where('status', 'active')->orderBy('name')->get(['id', 'name', 'vendor_no']) : [],
            'purchase_orders' => $procurement ? PurchaseOrder::query()->where('mda_id', $mdaId)->whereIn('status', ['issued', 'partially_received'])->latest('id')->get(['id', 'po_no', 'title', 'vendor_id']) : [],
            'workplans' => $procurement && $user->can('view-workplans') ? Workplan::query()->where('mda_id', $mdaId)->whereIn('status', ['approved', 'active'])->latest('year')->get(['id', 'title', 'year']) : [],
            'invoice_orders' => $payables ? PurchaseOrder::query()->where('mda_id', $mdaId)->whereIn('status', ['issued', 'partially_received', 'received', 'closed', 'cancelled'])->latest('id')->get(['id', 'po_no', 'title', 'vendor_id', 'currency']) : [],
            'supplier_invoices' => $payables ? SupplierInvoice::query()->where('mda_id', $mdaId)->withBalances()->latest('id')->get() : [],
            'invoice_vendors' => $payables ? Vendor::query()->where('mda_id', $mdaId)->orderBy('name')->get(['id', 'name', 'vendor_no']) : [],
        ]);
    }
}
