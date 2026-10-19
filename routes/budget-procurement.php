<?php

use App\Domain\Procurement\Services\ProcurementReportService;
use App\Http\Controllers\Api\BudgetManagementController as Budget;
use App\Http\Controllers\Api\BudgetProcurementOptionsController;
use App\Http\Controllers\Api\ProcurementFulfilmentController as Fulfilment;
use App\Http\Controllers\Api\ProcurementNotificationsController as Notifications;
use App\Http\Controllers\Api\ProcurementPlanningController as Planning;
use App\Http\Controllers\Api\ProcurementReportsController as Reports;
use App\Http\Controllers\Api\ProcurementSourcingController as Sourcing;
use App\Http\Controllers\Api\ProcurementWorkflowController as Workflow;
use App\Http\Controllers\Api\SupplierPayablesController as Payables;
use Illuminate\Support\Facades\Route;

Route::get('budget-procurement/options', BudgetProcurementOptionsController::class)
    ->name('api.budget-procurement.options');
Route::get('budget-procurement/reports/options', [Reports::class, 'options'])->name('api.budget-procurement.reports.options');
// The controller selects and checks an enabled accessible MDA, including when the primary MDA is disabled.
Route::prefix('budget-procurement/notifications')->name('api.budget-procurement.notifications.')->group(function (): void {
    Route::get('/', [Notifications::class, 'index'])->name('index');
    Route::get('/summary', [Notifications::class, 'summary'])->name('summary');
    Route::post('/read', [Notifications::class, 'read'])->name('read');
    Route::post('/read-all', [Notifications::class, 'readAll'])->name('read-all');
});

Route::middleware('ensure.module:budget_procurement')->prefix('budget-procurement')->name('api.budget-procurement.')->group(function (): void {
    Route::get('reports/dashboard', [Reports::class, 'dashboard'])->name('reports.dashboard');
    Route::get('reports/{reportType}', [Reports::class, 'show'])->whereIn('reportType', array_keys(ProcurementReportService::REPORTS))->name('reports.show');
    Route::get('reports/{reportType}/export/{format}', [Reports::class, 'export'])->whereIn('reportType', array_keys(ProcurementReportService::REPORTS))->whereIn('format', ['xlsx', 'pdf'])->name('reports.export');
    Route::middleware('can:view-supplier-payables')->group(function (): void {
        Route::get('supplier-invoices', [Payables::class, 'invoices'])->name('supplier-invoices.index');
        Route::get('supplier-invoices/{invoice}', [Payables::class, 'showInvoice'])->name('supplier-invoices.show');
        Route::get('supplier-payments', [Payables::class, 'payments'])->name('supplier-payments.index');
        Route::get('supplier-payments/{payment}', [Payables::class, 'showPayment'])->name('supplier-payments.show');
    });
    Route::post('supplier-invoices', [Payables::class, 'storeInvoice'])->middleware('can:manage-supplier-invoices')->name('supplier-invoices.store');
    Route::middleware('can:manage-supplier-invoices')->group(function (): void {
        Route::post('supplier-invoices/{invoice}/update', [Payables::class, 'updateInvoice'])->name('supplier-invoices.update');
        Route::post('supplier-invoices/{invoice}/items', [Payables::class, 'storeItem'])->name('supplier-invoice-items.store');
        Route::post('supplier-invoices/{invoice}/remove-item', [Payables::class, 'removeItem'])->name('supplier-invoice-items.remove');
    });
    foreach (['submit', 'approve', 'return', 'reject', 'cancel'] as $action) {
        Route::post('supplier-invoices/{invoice}/'.$action, [Payables::class, 'invoiceAction'])->defaults('payablesAction', $action)
            ->middleware('can:'.(in_array($action, ['approve', 'return', 'reject', 'cancel'], true) ? 'approve-supplier-invoices' : 'manage-supplier-invoices'))->name('supplier-invoices.'.$action);
    }
    Route::post('supplier-invoices/{invoice}/payments', [Payables::class, 'storePayment'])->middleware('can:record-supplier-payments')->name('supplier-payments.store');
    foreach (['submit', 'approve', 'mark-paid', 'reject', 'cancel'] as $action) {
        Route::post('supplier-payments/{payment}/'.$action, [Payables::class, 'paymentAction'])->defaults('payablesAction', $action)
            ->middleware('can:'.(in_array($action, ['approve', 'reject'], true) ? 'approve-supplier-payments' : 'record-supplier-payments'))->name('supplier-payments.'.$action);
    }
    Route::middleware('can:view-budget-cycles')->group(function (): void {
        Route::get('cycles', [Budget::class, 'cycles'])->name('cycles.index');
        Route::get('cycles/{cycle}', [Budget::class, 'showCycle'])->name('cycles.show');
        Route::get('accounts', [Budget::class, 'accounts'])->name('accounts.index');
        Route::get('allocations', [Budget::class, 'allocations'])->name('allocations.index');
        Route::get('allocations/{allocation}', [Budget::class, 'showAllocation'])->name('allocations.show');
        Route::get('releases', [Budget::class, 'releases'])->name('releases.index');
        Route::get('adjustments', [Budget::class, 'adjustments'])->name('adjustments.index');
        Route::get('commitments', [Budget::class, 'commitments'])->name('commitments.index');
        Route::get('transactions', [Budget::class, 'transactions'])->name('transactions.index');
    });
    Route::post('cycles', [Budget::class, 'storeCycle'])->middleware('can:create-budget-cycles')->name('cycles.store');
    Route::post('cycles/{cycle}/submit', [Budget::class, 'submitCycle'])->middleware('can:create-budget-cycles')->name('cycles.submit');
    Route::post('cycles/{cycle}/approve', [Budget::class, 'approveCycle'])->middleware('can:approve-budget-cycles')->name('cycles.approve');
    Route::post('accounts', [Budget::class, 'storeAccount'])->middleware('can:manage-budget-allocations')->name('accounts.store');
    Route::post('cycles/{cycle}/allocations', [Budget::class, 'storeAllocation'])->middleware('can:manage-budget-allocations')->name('allocations.store');
    Route::post('allocations/{allocation}/approve', [Budget::class, 'approveAllocation'])->middleware('can:approve-budget-cycles')->name('allocations.approve');
    Route::post('allocations/{allocation}/releases', [Budget::class, 'storeRelease'])->middleware('can:manage-budget-releases')->name('releases.store');
    Route::post('releases/{release}/post', [Budget::class, 'postRelease'])->middleware('can:manage-budget-releases')->name('releases.post');
    Route::post('allocations/{allocation}/adjustments', [Budget::class, 'storeAdjustment'])->middleware('can:manage-budget-adjustments')->name('adjustments.store');
    Route::post('adjustments/{adjustment}/approve', [Budget::class, 'approveAdjustment'])->middleware('can:approve-budget-cycles')->name('adjustments.approve');

    Route::middleware('can:view-procurement')->group(function (): void {
        Route::get('plans', [Planning::class, 'plans'])->name('plans.index');
        Route::get('plans/{plan}', [Planning::class, 'showPlan'])->name('plans.show');
        Route::get('requisitions', [Planning::class, 'requisitions'])->name('requisitions.index');
        Route::get('requisitions/{requisition}', [Planning::class, 'showRequisition'])->name('requisitions.show');
        Route::get('vendors', [Sourcing::class, 'vendors'])->name('vendors.index');
        Route::get('vendors/{vendor}', [Sourcing::class, 'showVendor'])->name('vendors.show');
        Route::get('solicitations', [Sourcing::class, 'solicitations'])->name('solicitations.index');
        Route::get('solicitations/{solicitation}', [Sourcing::class, 'showSolicitation'])->name('solicitations.show');
        Route::get('bids/{bid}', [Sourcing::class, 'showBid'])->name('bids.show');
        Route::get('awards', [Fulfilment::class, 'awards'])->name('awards.index');
        Route::get('awards/{award}', [Fulfilment::class, 'showAward'])->name('awards.show');
        Route::get('purchase-orders', [Fulfilment::class, 'purchaseOrders'])->name('purchase-orders.index');
        Route::get('purchase-orders/{purchaseOrder}', [Fulfilment::class, 'showPurchaseOrder'])->name('purchase-orders.show');
        Route::get('receipts', [Fulfilment::class, 'receipts'])->name('receipts.index');
        Route::get('receipts/{receipt}', [Fulfilment::class, 'showReceipt'])->name('receipts.show');
        Route::get('documents/{document}/download', [Sourcing::class, 'downloadDocument'])->name('documents.download');
    });
    Route::middleware('can:manage-procurement-plans')->group(function (): void {
        Route::post('plans', [Planning::class, 'storePlan'])->name('plans.store');
        Route::post('plans/{plan}/lines', [Planning::class, 'storePlanLine'])->name('plan-lines.store');
        Route::post('plans/{plan}/import-workplan', [Planning::class, 'importWorkplan'])->name('plans.import-workplan');
        Route::post('plans/{plan}/import-allocations', [Planning::class, 'importAllocations'])->name('plans.import-allocations');
    });
    Route::middleware('can:create-requisitions')->group(function (): void {
        Route::post('requisitions', [Planning::class, 'storeRequisition'])->name('requisitions.store');
        Route::post('requisitions/{requisition}/lines', [Planning::class, 'storeRequisitionLine'])->name('requisition-lines.store');
        Route::post('requisitions/{requisition}/submit', [Planning::class, 'submitRequisition'])->name('requisitions.submit');
    });
    Route::post('requisitions/{requisition}/review-budget', [Planning::class, 'reviewBudget'])->middleware('can:review-budget-availability')->name('requisitions.review-budget');
    Route::post('requisitions/{requisition}/approve', [Planning::class, 'approveRequisition'])->middleware('can:approve-requisitions')->name('requisitions.approve');
    Route::post('vendors', [Sourcing::class, 'storeVendor'])->middleware('can:manage-vendors')->name('vendors.store');
    Route::middleware('can:manage-sourcing')->group(function (): void {
        Route::post('requisitions/{requisition}/solicitations', [Sourcing::class, 'storeSolicitation'])->name('solicitations.store');
        Route::post('solicitations/{solicitation}/publish', [Sourcing::class, 'publish'])->name('solicitations.publish');
        Route::post('solicitations/{solicitation}/close', [Sourcing::class, 'close'])->name('solicitations.close');
        Route::post('solicitations/{solicitation}/bids', [Sourcing::class, 'receiveBid'])->name('bids.store');
    });
    Route::post('bids/{bid}/evaluate', [Fulfilment::class, 'evaluate'])->middleware('can:evaluate-bids')->name('bids.evaluate');
    Route::post('evaluations/{evaluation}/recommend', [Fulfilment::class, 'recommend'])->middleware('can:evaluate-bids')->name('evaluations.recommend');
    Route::post('awards/{award}/approve', [Fulfilment::class, 'approveAward'])->middleware('can:approve-awards')->name('awards.approve');
    foreach (['return', 'reject', 'cancel', 'close', 'correct'] as $action) {
        Route::post('requisitions/{requisition}/'.$action, [Workflow::class, 'requisition'])
            ->defaults('workflowAction', $action)->middleware('can:'.($action === 'correct' ? 'create-requisitions' : 'approve-requisitions'))->name('requisitions.'.$action);
    }
    foreach (['amend', 'close'] as $action) {
        Route::post('plans/{plan}/'.$action, [Workflow::class, 'plan'])->defaults('workflowAction', $action)
            ->middleware('can:manage-procurement-plans')->name('plans.'.$action);
    }
    Route::post('awards/{award}/reject', [Workflow::class, 'award'])->middleware('can:approve-awards')->name('awards.reject');
    foreach (['amend', 'cancel', 'close'] as $action) {
        Route::post('purchase-orders/{purchaseOrder}/'.$action, [Workflow::class, 'order'])->defaults('workflowAction', $action)
            ->middleware('can:manage-purchase-orders')->name('purchase-orders.'.$action);
    }
    Route::post('receipts/{receipt}/cancel', [Workflow::class, 'receipt'])->middleware('can:record-procurement-receipts')->name('receipts.cancel');
    Route::post('awards/{award}/purchase-order', [Fulfilment::class, 'generatePurchaseOrder'])->middleware('can:manage-purchase-orders')->name('purchase-orders.store');
    Route::middleware('can:record-procurement-receipts')->group(function (): void {
        Route::post('purchase-orders/{purchaseOrder}/receipts', [Fulfilment::class, 'storeReceipt'])->name('receipts.store');
        Route::post('receipts/{receipt}/items', [Fulfilment::class, 'storeReceiptItem'])->name('receipt-items.store');
        Route::post('receipts/{receipt}/post', [Fulfilment::class, 'postReceipt'])->name('receipts.post');
    });
    // Document permissions depend on the target type and are checked by the controller.
    Route::post('{targetType}/{targetId}/documents', [Sourcing::class, 'storeDocument'])->whereIn('targetType', ['vendors', 'solicitations', 'bids'])->whereNumber('targetId')->name('documents.store');
    Route::delete('documents/{document}', [Sourcing::class, 'deleteDocument'])->name('documents.destroy');
});
