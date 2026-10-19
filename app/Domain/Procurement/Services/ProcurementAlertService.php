<?php

namespace App\Domain\Procurement\Services;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Budget\Models\BudgetAllocation;
use App\Domain\Procurement\Models\ProcurementAlertRead;
use App\Domain\Procurement\Models\ProcurementAward;
use App\Domain\Procurement\Models\ProcurementRequisition;
use App\Domain\Procurement\Models\PurchaseOrder;
use App\Domain\Procurement\Models\SupplierInvoice;
use App\Domain\Procurement\Models\SupplierPayment;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;

class ProcurementAlertService
{
    public const READ_PERMISSIONS = ['view-budget-cycles', 'view-procurement', 'view-supplier-payables'];

    public const CATEGORIES = [
        'approvals' => 'Pending approvals', 'corrections' => 'Returns & rejections',
        'invoices' => 'Overdue invoices', 'deliveries' => 'Incomplete deliveries', 'budget' => 'Low budget',
    ];

    public function __construct(private ProcurementReportService $reports) {}

    public function feed(User $user, int $mdaId, bool $details = true): Collection
    {
        $alerts = collect();
        $definitions = [
            [ProcurementRequisition::class, 'requisitions', 'requisition_no', 'Requisition', 'view-procurement', 'approve-requisitions', 'create-requisitions', 'requested_by', 'submitted', 'submitted_at'],
            [ProcurementAward::class, 'awards', 'award_no', 'Award', 'view-procurement', 'approve-awards', 'evaluate-bids', 'recommended_by', 'recommended', 'recommended_at'],
            [SupplierInvoice::class, 'supplier-invoices', 'invoice_no', 'Invoice', 'view-supplier-payables', 'approve-supplier-invoices', 'manage-supplier-invoices', 'prepared_by', 'submitted', 'submitted_at'],
            [SupplierPayment::class, 'supplier-payments', 'payment_no', 'Payment', 'view-supplier-payables', 'approve-supplier-payments', 'record-supplier-payments', 'prepared_by', 'submitted', 'submitted_at'],
        ];
        foreach ($definitions as [$model, $area, $reference, $label, $read, $approve, $manage, $owner, $pending, $date]) {
            if (! $user->can($read)) {
                continue;
            }
            $statuses = ['returned', 'rejected'];
            if ($user->can($approve)) {
                $statuses[] = $pending;
            }
            if ($model === ProcurementRequisition::class && $user->can($approve)) {
                $statuses[] = 'budget_reviewed';
            }
            $query = $model::query()->where('mda_id', $mdaId)->whereIn('status', $statuses);
            foreach ($query->lazyById(200)->chunk(200) as $records) {
                $changes = $this->changes($model, $records->pluck('id')->all(), $mdaId, $details);
                foreach ($records as $record) {
                    $approval = $record->status === $pending || ($record instanceof ProcurementRequisition && $record->status === 'budget_reviewed');
                    if ($approval && $record instanceof SupplierPayment && (int) $record->prepared_by === $user->id) {
                        continue;
                    }
                    if (! $approval && (int) $record->{$owner} !== $user->id && ! $user->can($manage)) {
                        continue;
                    }
                    $change = $changes[$record->id] ?? null;
                    $occurred = $change?->occurred_at ?? ($approval ? ($record->{$date} ?? $record->updated_at) : ($record->rejected_at ?? $record->updated_at));
                    $version = $change?->id ?? (string) $occurred;
                    $reason = $approval ? null : ($change?->context['reason'] ?? $record->rejection_reason ?? null);
                    $alerts->push($this->alert($mdaId, $area, $record->id, $approval ? 'approvals' : 'corrections', $approval ? 'approval' : $record->status,
                        $version, $record->{$reference}, $approval ? $label.' awaiting approval' : $label.' '.$record->status,
                        $approval ? 'Approval required for '.$record->{$reference}.'.' : ($reason ?: 'No reason recorded.'),
                        $approval ? 'info' : ($record->status === 'rejected' ? 'danger' : 'warning'), $occurred,
                        $approval || $record->status === 'returned', ['reason' => $reason]));
                }
            }
        }

        if ($user->can('view-supplier-payables')) {
            $query = SupplierInvoice::query()->where('mda_id', $mdaId)->where('status', 'approved')->whereNotNull('due_date')
                ->whereDate('due_date', '<', now('Africa/Lagos')->toDateString())->withBalances()->with('vendor');
            foreach ($query->lazyById(200) as $invoice) {
                if (! BigDecimal::of($invoice->outstanding_amount)->isPositive()) {
                    continue;
                }
                $alerts->push($this->alert($mdaId, 'supplier-invoices', $invoice->id, 'invoices', 'overdue', $invoice->due_date->toDateString(),
                    $invoice->invoice_no, 'Supplier invoice overdue', ($invoice->vendor?->name ?? 'Supplier').' / due '.$invoice->due_date->toDateString(),
                    'danger', $invoice->due_date, true, ['amount' => $invoice->outstanding_amount, 'currency' => $invoice->currency, 'due_date' => $invoice->due_date->toDateString()]));
            }
        }

        if ($user->can('view-procurement')) {
            $query = PurchaseOrder::query()->where('mda_id', $mdaId)->whereIn('status', ['issued', 'partially_received'])
                ->with(['vendor', 'items' => fn ($q) => $q->withSum(['receiptItems as accepted_quantity' => fn ($lines) => $lines->where('mda_id', $mdaId)->whereHas('receipt', fn ($receipt) => $receipt->where('mda_id', $mdaId)->where('status', 'posted'))], 'quantity_accepted')]);
            foreach ($query->lazyById(100) as $order) {
                $incomplete = $order->items->filter(fn ($item) => BigDecimal::of($item->quantity)->isGreaterThan((string) ($item->accepted_quantity ?? 0)));
                if ($incomplete->isEmpty()) {
                    continue;
                }
                $late = $order->expected_delivery_date && $order->expected_delivery_date->toDateString() < now('Africa/Lagos')->toDateString();
                $alerts->push($this->alert($mdaId, 'purchase-orders', $order->id, 'deliveries', $late ? 'late-delivery' : 'incomplete-delivery',
                    $order->expected_delivery_date?->toDateString() ?? 'undated', $order->po_no, $late ? 'Delivery overdue' : 'Delivery incomplete',
                    ($order->vendor?->name ?? 'Supplier').' / '.$incomplete->count().' incomplete order lines'.($order->expected_delivery_date ? ' / due '.$order->expected_delivery_date->toDateString() : ''),
                    $late ? 'warning' : 'info', $order->expected_delivery_date ?? $order->order_date ?? $order->created_at, true,
                    ['incomplete_lines' => $incomplete->count(), 'due_date' => $order->expected_delivery_date?->toDateString()]));
            }
        }

        if ($user->can('view-budget-cycles')) {
            $active = BudgetAllocation::query()->where('mda_id', $mdaId)->where('status', 'approved')
                ->whereHas('cycle', fn ($q) => $q->where('mda_id', $mdaId)->whereNotIn('status', ['closed', 'locked'])->whereNull('closed_at')->whereNull('locked_at'))
                ->withMax(['transactions' => fn ($q) => $q->where('mda_id', $mdaId)], 'id')->get(['id'])->pluck('transactions_max_id', 'id');
            $threshold = max(0, min(100, (float) config('procurement_alerts.low_budget_percent', 10)));
            foreach ($this->reports->rows('budget-utilization', ['mda_id' => $mdaId, 'date_from' => now('Africa/Lagos')->startOfYear()->toDateString(), 'date_to' => now('Africa/Lagos')->toDateString()]) as $row) {
                if (! $active->has($row['id']) || ! BigDecimal::of($row['allocated'])->isPositive()) {
                    continue;
                }
                $percent = BigDecimal::of($row['available'])->multipliedBy(100)->dividedBy($row['allocated'], 4, RoundingMode::HALF_UP);
                if (BigDecimal::of($row['available'])->multipliedBy(100)->isGreaterThan(BigDecimal::of($row['allocated'])->multipliedBy((string) $threshold))) {
                    continue;
                }
                $empty = BigDecimal::of($row['available'])->isLessThanOrEqualTo(0);
                $alerts->push($this->alert($mdaId, 'allocations', $row['id'], 'budget', 'low-budget', $active->get($row['id']).':'.($empty ? 'exhausted' : 'low'),
                    $row['allocation'], $empty ? 'Available budget exhausted' : 'Low available budget', (string) $percent->toScale(2, RoundingMode::HALF_UP).'% available / '.$row['account'],
                    $empty ? 'danger' : 'warning', null, true, ['amount' => $row['available'], 'currency' => 'NGN', 'available_percent' => (string) $percent, 'threshold_percent' => $threshold]));
            }
        }

        $reads = ProcurementAlertRead::query()->where('mda_id', $mdaId)->where('user_id', $user->id)->get()->keyBy('alert_key');
        $reminderCutoff = now()->subDays(max(1, (int) config('procurement_alerts.reminder_days', 3)));

        return $alerts->map(function ($alert) use ($reads, $reminderCutoff) {
            $read = $reads->get($alert['id']);
            $alert['is_reminder'] = (bool) ($read && $alert['repeat'] && $read->read_at <= $reminderCutoff);
            $alert['unread'] = ! $read || $alert['is_reminder'];
            $alert['read_at'] = $read?->read_at->toIso8601String();
            unset($alert['repeat']);

            return $alert;
        })->sort(function ($first, $second) {
            $rank = ['danger' => 0, 'warning' => 1, 'info' => 2];

            return ($rank[$first['severity']] <=> $rank[$second['severity']]) ?: strcmp($second['occurred_at'] ?? '', $first['occurred_at'] ?? '') ?: strcmp($first['id'], $second['id']);
        })->values();
    }

    private function changes(string $model, array $ids, int $mdaId, bool $details): Collection
    {
        if (! $ids) {
            return collect();
        }

        // Audit IDs distinguish repeat submissions even within one database timestamp tick.
        return AuditLog::query()->where(fn ($q) => $q->where('context->mda_id', $mdaId)->orWhere('after_values->mda_id', $mdaId))
            ->where('auditable_type', $model)->whereIn('auditable_id', $ids)
            ->where(function ($q) {
                $q->whereIn('event_code', ['procurement.workflow.return', 'procurement.workflow.reject', 'procurement.payables.return', 'procurement.payables.reject', 'procurement.payables.submit', 'procurement.award_recommended', 'procurement.award_recommendation_updated'])
                    ->orWhere(fn ($submitted) => $submitted->where('event_code', 'updated')->where('context->source', 'procurement_requisition.submitted'));
            })
            ->latest('id')->get($details ? ['id', 'auditable_id', 'context', 'occurred_at'] : ['id', 'auditable_id', 'occurred_at'])->unique('auditable_id')->keyBy('auditable_id');
    }

    private function alert(int $mda, string $area, int $record, string $category, string $type, mixed $version, string $reference, string $title, string $message, string $severity, mixed $occurred, bool $repeat, array $extra = []): array
    {
        return ['id' => hash('sha256', implode(':', [$mda, $area, $record, $type, $version])), 'mda_id' => $mda, 'area' => $area, 'record_id' => $record,
            'category' => $category, 'type' => $type, 'reference' => $reference, 'title' => $title, 'message' => $message,
            'severity' => $severity, 'occurred_at' => $occurred?->toIso8601String(), 'repeat' => $repeat,
            'url' => '/budget-procurement/'.$area.'/'.$record.'?mda_id='.$mda, ...$extra];
    }
}
