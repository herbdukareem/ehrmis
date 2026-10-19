<?php

namespace App\Domain\Procurement\Services;

use App\Domain\Budget\Models\BudgetAllocation;
use App\Domain\Procurement\Models\ProcurementRequisition;
use App\Domain\Procurement\Models\SupplierInvoice;
use App\Domain\Procurement\Models\SupplierPayment;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class ProcurementReportService
{
    public const REPORTS = [
        'budget-utilization' => ['title' => 'Budget utilization', 'permission' => 'view-budget-cycles'],
        'procurement-tracking' => ['title' => 'Procurement tracking', 'permission' => 'view-procurement'],
        'supplier-balances' => ['title' => 'Supplier balances', 'permission' => 'view-supplier-payables'],
        'payment-history' => ['title' => 'Payment history', 'permission' => 'view-supplier-payables'],
    ];

    public function columns(string $type): array
    {
        $columns = match ($type) {
            'budget-utilization' => [
                ['allocation', 'Allocation', 'text'], ['account', 'Account', 'text'], ['department', 'Department', 'text'],
                ['allocated', 'Allocated', 'money'], ['released', 'Released', 'money'], ['committed', 'Committed', 'money'],
                ['spent', 'Expenditure', 'money'], ['available', 'Available', 'money'], ['period_spent', 'Period expenditure', 'money'],
            ],
            'procurement-tracking' => [
                ['reference', 'Requisition', 'link'], ['title', 'Title', 'text'], ['status', 'Current status', 'status'],
                ['created_date', 'Raised', 'date'], ['sourcing', 'Sourcing', 'number'], ['awards', 'Approved awards', 'number'],
                ['orders', 'Orders', 'number'], ['open_orders', 'Open orders', 'number'], ['delivery_progress', 'Delivery', 'percent'],
            ],
            'supplier-balances' => [
                ['supplier', 'Supplier', 'text'], ['reference', 'Invoice', 'link'], ['order_reference', 'Order', 'text'],
                ['approved_date', 'Approved', 'date'], ['due_date', 'Due', 'date'], ['currency', 'Currency', 'text'],
                ['invoiced', 'Approved amount', 'money'], ['paid', 'Paid to cutoff', 'money'], ['outstanding', 'Outstanding', 'money'],
                ['overdue', 'Overdue', 'money'], ['aging', 'Age', 'text'],
            ],
            'payment-history' => [
                ['reference', 'Payment', 'link'], ['supplier', 'Supplier', 'text'], ['invoice_reference', 'Invoice', 'text'],
                ['currency', 'Currency', 'text'], ['amount', 'Amount', 'money'], ['status', 'Current status', 'status'],
                ['approved_date', 'Approved', 'date'], ['approved_by', 'Approver', 'text'], ['paid_date', 'Settled', 'date'],
                ['payment_reference', 'Settlement reference', 'text'], ['method', 'Method', 'text'],
            ],
        };

        return array_map(fn ($column) => ['key' => $column[0], 'label' => $column[1], 'type' => $column[2]], $columns);
    }

    public function build(string $type, array $filters, int $page = 1, int $perPage = 25, ?int $exportLimit = null): array
    {
        $rows = [];
        $totals = [];
        $statuses = [];
        $suppliers = [];
        $count = 0;
        $deliveryTotal = 0;
        $counts = [];
        foreach ($this->rows($type, $filters) as $row) {
            $count++;
            if ($exportLimit && $count > $exportLimit) {
                throw ValidationException::withMessages(['export' => "This export exceeds {$exportLimit} rows. Narrow the filters before exporting."]);
            }
            if ($exportLimit || ($count > ($page - 1) * $perPage && $count <= $page * $perPage)) {
                $rows[] = $row;
            }
            $currency = $row['currency'] ?? 'NGN';
            $moneyKeys = match ($type) {
                'budget-utilization' => ['allocated', 'released', 'committed', 'spent', 'available', 'opening_available', 'period_allocated', 'period_released', 'period_spent'],
                'supplier-balances' => ['invoiced', 'paid', 'outstanding', 'overdue', 'period_approved', 'period_paid', 'not_due', 'days_1_30', 'days_31_60', 'days_61_90', 'days_90_plus'],
                'payment-history' => ['settled', 'approved_pending'],
                default => [],
            };
            foreach ($moneyKeys as $key) {
                $totals[$currency][$key] = $this->add($totals[$currency][$key] ?? '0.00', $row[$key] ?? '0.00');
            }
            if (isset($row['status'])) {
                $statuses[$row['status']] = ($statuses[$row['status']] ?? 0) + 1;
            }
            if ($type === 'procurement-tracking') {
                foreach (['sourcing', 'awards', 'orders', 'open_orders', 'ordered_lines', 'accepted_lines'] as $key) {
                    $counts[$key] = ($counts[$key] ?? 0) + $row[$key];
                }
                $deliveryTotal += $row['delivery_progress'] * $row['ordered_lines'];
            }
            if ($type === 'supplier-balances') {
                $key = $row['vendor_id'].'|'.$currency;
                $suppliers[$key] ??= ['id' => $key, 'vendor_id' => $row['vendor_id'], 'supplier' => $row['supplier'], 'currency' => $currency, 'invoices' => 0, 'outstanding' => '0.00', 'overdue' => '0.00'];
                $suppliers[$key]['invoices']++;
                foreach (['outstanding', 'overdue'] as $field) {
                    $suppliers[$key][$field] = $this->add($suppliers[$key][$field], $row[$field]);
                }
            }
        }

        return [
            'type' => $type, 'title' => self::REPORTS[$type]['title'], 'columns' => $this->columns($type), 'rows' => $rows,
            'summary' => ['count' => $count, 'currencies' => $totals, 'counts' => $counts, 'statuses' => $statuses,
                'delivery_progress' => ! empty($counts['ordered_lines']) ? round($deliveryTotal / $counts['ordered_lines'], 2) : 0,
                'suppliers' => array_values($suppliers)],
            'meta' => ['current_page' => $page, 'per_page' => $perPage, 'last_page' => max(1, (int) ceil($count / $perPage)),
                'total' => $count, 'from' => count($rows) ? ($page - 1) * $perPage + 1 : null,
                'to' => count($rows) ? min($page * $perPage, $count) : null,
                'generated_at' => now()->toIso8601String(), 'filters' => $filters],
        ];
    }

    public function rows(string $type, array $filters): Generator
    {
        yield from match ($type) {
            'budget-utilization' => $this->budgets($filters),
            'procurement-tracking' => $this->procurement($filters),
            'supplier-balances' => $this->balances($filters),
            'payment-history' => $this->payments($filters),
        };
    }

    private function budgets(array $filters): Generator
    {
        $query = BudgetAllocation::query()->where('mda_id', $filters['mda_id'])->with(['account', 'department',
            'transactions' => fn ($q) => $q->where('mda_id', $filters['mda_id'])->whereDate('transaction_date', '<=', $filters['date_to'])
                ->select('budget_allocation_id', 'transaction_type')->selectRaw('SUM(signed_amount) as closing_amount')
                ->selectRaw('SUM(CASE WHEN transaction_date < ? THEN signed_amount ELSE 0 END) as opening_amount', [$filters['date_from']])
                ->groupBy('budget_allocation_id', 'transaction_type'),
        ])
            ->when($filters['year'] ?? null, fn ($q, $year) => $q->whereHas('cycle', fn ($cycle) => $cycle->where('year', $year)))
            ->whereHas('transactions', fn ($q) => $q->where('mda_id', $filters['mda_id'])->whereDate('transaction_date', '<=', $filters['date_to']));
        foreach ($query->lazyById(200) as $allocation) {
            $closing = $opening = [];
            foreach ($allocation->transactions as $transaction) {
                $type = $transaction->transaction_type;
                $closing[$type] = $this->add($transaction->closing_amount, 0);
                $opening[$type] = $this->add($transaction->opening_amount, 0);
            }
            $end = $this->ledgerBalances($closing);
            $start = $this->ledgerBalances($opening);
            yield ['id' => $allocation->id, 'area' => 'allocations', 'reference' => $allocation->title, 'allocation' => $allocation->title,
                'account' => trim(($allocation->account?->code ?? '').' - '.($allocation->account?->name ?? ''), ' -'),
                'department' => $allocation->department?->name ?? 'Unassigned', 'currency' => 'NGN', ...$end,
                'opening_available' => $start['available'], 'period_allocated' => $this->subtract($end['allocated'], $start['allocated']),
                'period_released' => $this->subtract($end['released'], $start['released']), 'period_spent' => $this->subtract($end['spent'], $start['spent'])];
        }
    }

    private function ledgerBalances(array $amounts): array
    {
        $allocated = $this->add($amounts['allocation'] ?? 0, $amounts['adjustment'] ?? 0);
        $committed = $this->add($this->add($amounts['commitment'] ?? 0, $amounts['commitment_release'] ?? 0), $amounts['commitment_liquidation'] ?? 0);
        $spent = $this->add($amounts['expenditure'] ?? 0, 0);

        return ['allocated' => $allocated, 'released' => $this->add($amounts['release'] ?? 0, 0), 'committed' => $committed, 'spent' => $spent,
            'available' => $this->subtract($this->subtract($allocated, $committed), $spent)];
    }

    private function requisitions(array $filters): Builder
    {
        return ProcurementRequisition::query()->where('mda_id', $filters['mda_id'])->when($filters['year'] ?? null, function ($query, $year) {
            $query->where(function ($q) use ($year) {
                $q->whereHas('budgetCycle', fn ($cycle) => $cycle->where('year', $year))
                    ->orWhere(fn ($unbudgeted) => $unbudgeted->whereNull('budget_cycle_id')->whereHas('plan', fn ($plan) => $plan->where('year', $year)))
                    ->orWhere(fn ($manual) => $manual->whereNull('budget_cycle_id')->whereNull('procurement_plan_id')->whereYear('created_at', $year));
            });
        });
    }

    private function orderYear(Builder $query, array $filters, string $relation): void
    {
        if (empty($filters['year'])) {
            return;
        }
        $query->whereHas($relation, function ($order) use ($filters) {
            $order->where('mda_id', $filters['mda_id'])->where(function ($q) use ($filters) {
                $q->whereIn('procurement_requisition_id', $this->requisitions($filters)->select('id'))
                    ->orWhere(fn ($manual) => $manual->whereNull('procurement_requisition_id')->where(function ($dates) use ($filters) {
                        $dates->whereYear('order_date', $filters['year'])->orWhere(fn ($fallback) => $fallback->whereNull('order_date')->whereYear('created_at', $filters['year']));
                    }));
            });
        });
    }

    private function procurement(array $filters): Generator
    {
        $from = $this->start($filters['date_from']);
        $to = $this->end($filters['date_to']);
        $vendor = $filters['vendor_id'] ?? null;
        $query = $this->requisitions($filters)->whereBetween('created_at', [$from, $to])
            ->with(['solicitations.bids', 'solicitations.award', 'purchaseOrders.items.receiptItems.receipt']);
        if ($vendor) {
            $query->where(fn ($q) => $q->whereHas('purchaseOrders', fn ($p) => $p->where('vendor_id', $vendor))->orWhereHas('solicitations.bids', fn ($b) => $b->where('vendor_id', $vendor)));
        }
        foreach ($query->lazyById(100) as $req) {
            $sources = $req->solicitations->filter(fn ($source) => $source->created_at <= $to && (! $vendor || $source->bids->contains('vendor_id', $vendor) || $source->award?->vendor_id === (int) $vendor));
            $orders = $req->purchaseOrders->filter(fn ($order) => $order->created_at <= $to && (! $vendor || (int) $order->vendor_id === (int) $vendor));
            $items = $orders->flatMap->items;
            $progress = 0;
            $acceptedLines = 0;
            foreach ($items as $item) {
                $accepted = $item->receiptItems->filter(fn ($line) => $line->receipt->status === 'posted' && $line->receipt->posted_at <= $to)
                    ->reduce(fn ($sum, $line) => $sum->plus($line->quantity_accepted), BigDecimal::zero());
                $ratio = BigDecimal::of($item->quantity)->isPositive() ? min(1, $accepted->dividedBy($item->quantity, 6, RoundingMode::HALF_UP)->toFloat()) : 0;
                $progress += $ratio;
                if ($ratio >= 1) {
                    $acceptedLines++;
                }
            }
            yield ['id' => $req->id, 'area' => 'requisitions', 'reference' => $req->requisition_no, 'title' => $req->title,
                'status' => $req->status, 'created_date' => $req->created_at->setTimezone('Africa/Lagos')->toDateString(),
                'sourcing' => $sources->count(), 'awards' => $sources->filter(fn ($s) => $s->award?->approved_at && $s->award->approved_at <= $to)->count(),
                'orders' => $orders->count(), 'open_orders' => $orders->whereIn('status', ['issued', 'partially_received', 'received'])->count(),
                'ordered_lines' => $items->count(), 'accepted_lines' => $acceptedLines, 'delivery_progress' => $items->count() ? round($progress / $items->count() * 100, 2) : 0];
        }
    }

    private function balances(array $filters): Generator
    {
        $end = $this->end($filters['date_to']);
        $query = SupplierInvoice::query()->where('mda_id', $filters['mda_id'])->whereNotNull('approved_at')->where('approved_at', '<=', $end)
            ->whereDate('invoice_date', '<=', $filters['date_to'])->where(fn ($q) => $q->whereNull('cancelled_at')->orWhere('cancelled_at', '>', $end))
            ->when($filters['vendor_id'] ?? null, fn ($q, $vendor) => $q->where('vendor_id', $vendor))->with(['vendor', 'purchaseOrder', 'payments']);
        $this->orderYear($query, $filters, 'purchaseOrder');
        foreach ($query->lazyById(200) as $invoice) {
            $paid = $periodPaid = '0.00';
            foreach ($invoice->payments as $payment) {
                if ($payment->status !== 'paid' || ! $payment->paid_date || $payment->paid_date->toDateString() > $filters['date_to']) {
                    continue;
                }
                $paid = $this->add($paid, $payment->amount);
                if ($payment->paid_date->toDateString() >= $filters['date_from']) {
                    $periodPaid = $this->add($periodPaid, $payment->amount);
                }
            }
            $outstanding = $this->subtract($invoice->total_amount, $paid);
            $days = $invoice->due_date ? max(0, (int) CarbonImmutable::parse($invoice->due_date->toDateString())->diffInDays(CarbonImmutable::parse($filters['date_to']), false)) : 0;
            $bucket = ! $days ? 'not_due' : ($days <= 30 ? 'days_1_30' : ($days <= 60 ? 'days_31_60' : ($days <= 90 ? 'days_61_90' : 'days_90_plus')));
            yield ['id' => $invoice->id, 'area' => 'supplier-invoices', 'vendor_id' => $invoice->vendor_id, 'supplier' => $invoice->vendor?->name ?? 'Unknown supplier',
                'reference' => $invoice->invoice_no, 'order_reference' => $invoice->purchaseOrder?->po_no, 'currency' => $invoice->currency,
                'approved_date' => $invoice->approved_at->setTimezone('Africa/Lagos')->toDateString(), 'due_date' => $invoice->due_date?->toDateString(),
                'invoiced' => $invoice->total_amount, 'paid' => $paid, 'outstanding' => $outstanding, 'overdue' => $days ? $outstanding : '0.00',
                'aging' => BigDecimal::of($outstanding)->isZero() ? 'Settled' : (! $invoice->due_date ? 'No due date' : ($days ? $days.' days overdue' : 'Not due')),
                'period_approved' => $invoice->approved_at >= $this->start($filters['date_from']) ? $invoice->total_amount : '0.00', 'period_paid' => $periodPaid,
                'not_due' => $bucket === 'not_due' ? $outstanding : '0.00', 'days_1_30' => $bucket === 'days_1_30' ? $outstanding : '0.00',
                'days_31_60' => $bucket === 'days_31_60' ? $outstanding : '0.00', 'days_61_90' => $bucket === 'days_61_90' ? $outstanding : '0.00', 'days_90_plus' => $bucket === 'days_90_plus' ? $outstanding : '0.00'];
        }
    }

    private function payments(array $filters): Generator
    {
        $query = SupplierPayment::query()->where('mda_id', $filters['mda_id'])
            ->when($filters['vendor_id'] ?? null, fn ($q, $vendor) => $q->where('vendor_id', $vendor))
            ->with(['vendor', 'invoice', 'approvedBy']);
        $this->orderYear($query, $filters, 'invoice.purchaseOrder');
        $query->where(function ($q) use ($filters) {
            $q->where(fn ($paid) => $paid->where('status', 'paid')->whereBetween('paid_date', [$filters['date_from'], $filters['date_to']]))
                ->orWhereBetween('approved_at', [$this->start($filters['date_from']), $this->end($filters['date_to'])])
                ->orWhere(fn ($prepared) => $prepared->whereNull('approved_at')->where('status', '!=', 'paid')->whereBetween('created_at', [$this->start($filters['date_from']), $this->end($filters['date_to'])]));
        });
        foreach ($query->lazyById(200) as $payment) {
            $settled = $payment->status === 'paid' && $payment->paid_date?->toDateString() >= $filters['date_from'] && $payment->paid_date->toDateString() <= $filters['date_to'];
            $pending = $payment->approved_at && $payment->approved_at <= $this->end($filters['date_to'])
                && (! $payment->paid_date || $payment->paid_date->toDateString() > $filters['date_to'])
                && (! $payment->cancelled_at || $payment->cancelled_at > $this->end($filters['date_to']))
                && (! $payment->rejected_at || $payment->rejected_at > $this->end($filters['date_to']));
            yield ['id' => $payment->id, 'area' => 'supplier-payments', 'reference' => $payment->payment_no, 'supplier' => $payment->vendor?->name ?? 'Unknown supplier',
                'invoice_reference' => $payment->invoice?->invoice_no, 'currency' => $payment->currency, 'amount' => $payment->amount,
                'status' => $payment->status, 'approved_date' => $payment->approved_at?->setTimezone('Africa/Lagos')->toDateString(), 'approved_by' => $payment->approvedBy?->name,
                'paid_date' => $payment->paid_date?->toDateString(), 'payment_reference' => $payment->payment_reference, 'method' => str_replace('_', ' ', $payment->method),
                'settled' => $settled ? $payment->amount : '0.00', 'approved_pending' => $pending ? $payment->amount : '0.00'];
        }
    }

    private function start(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, 'Africa/Lagos')->startOfDay()->utc();
    }

    private function end(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, 'Africa/Lagos')->endOfDay()->utc();
    }

    private function add(mixed $first, mixed $second): string
    {
        return (string) BigDecimal::of((string) $first)->plus((string) $second)->toScale(2, RoundingMode::HALF_UP);
    }

    private function subtract(mixed $first, mixed $second): string
    {
        return (string) BigDecimal::of((string) $first)->minus((string) $second)->toScale(2, RoundingMode::HALF_UP);
    }
}
