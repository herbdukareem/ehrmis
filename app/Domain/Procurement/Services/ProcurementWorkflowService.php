<?php

namespace App\Domain\Procurement\Services;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Budget\Models\BudgetCommitment;
use App\Domain\Budget\Services\BudgetLedgerService;
use App\Domain\Procurement\Models\ProcurementAward;
use App\Domain\Procurement\Models\ProcurementPlan;
use App\Domain\Procurement\Models\ProcurementReceipt;
use App\Domain\Procurement\Models\ProcurementRequisition;
use App\Domain\Procurement\Models\ProcurementRequisitionLine;
use App\Domain\Procurement\Models\PurchaseOrder;
use App\Domain\Procurement\Models\PurchaseOrderItem;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProcurementWorkflowService
{
    public function __construct(private BudgetLedgerService $ledger, private AuditLogService $audit) {}

    public function requisition(ProcurementRequisition $record, string $action, array $data, User $actor): ProcurementRequisition
    {
        return DB::transaction(function () use ($record, $action, $data, $actor) {
            $record = ProcurementRequisition::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();
            $before = $record->load('lines')->toArray();
            if ($action === 'correct') {
                $this->status($record, ['draft', 'returned']);
                $record->fill(array_intersect_key($data, array_flip(['title', 'description', 'justification'])));
                if (! empty($data['line_id'])) {
                    $line = $record->lines()->whereKey($data['line_id'])->firstOrFail();
                    if ($data['remove_line'] ?? false) {
                        $line->delete();
                    } else {
                        $line->fill(array_intersect_key($data, array_flip(['item_description', 'specification', 'quantity', 'unit', 'estimated_amount', 'estimated_unit_cost', 'notes'])));
                        $line->forceFill(['budget_check_status' => 'pending', 'budget_check_summary' => null])->save();
                    }
                }
                $record->forceFill([
                    'total_amount' => $record->lines()->sum('estimated_amount'),
                    'budget_review_status' => 'pending', 'budget_reviewed_by' => null, 'budget_reviewed_at' => null,
                    'summary' => [...($record->summary ?? []), 'line_count' => $record->lines()->count(), 'total_amount' => (float) $record->lines()->sum('estimated_amount'), 'budget_review' => null],
                ]);
            } elseif (in_array($action, ['return', 'reject'], true)) {
                $this->status($record, ['submitted', 'budget_reviewed']);
                $record->status = $action === 'return' ? 'returned' : 'rejected';
                $record->lines()->update(['status' => $record->status, 'budget_check_status' => 'pending', 'budget_check_summary' => null]);
                $record->forceFill(['budget_review_status' => 'pending', 'budget_reviewed_by' => null, 'budget_reviewed_at' => null]);
                $record->summary = [...($record->summary ?? []), 'budget_review' => null];
                if ($action === 'reject') {
                    $record->forceFill(['rejected_by' => $actor->id, 'rejected_at' => now(), 'rejection_reason' => $data['reason']]);
                }
            } else {
                $target = $action === 'cancel' ? 'cancelled' : 'closed';
                if ($record->status === $target) {
                    return $record;
                }
                $this->status($record, $action === 'cancel' ? ['draft', 'returned', 'submitted', 'budget_reviewed', 'approved'] : ['approved']);
                $orders = $record->purchaseOrders()->get();
                $this->ensure(! $orders->contains(fn ($order) => ! in_array($order->status, ['cancelled', 'closed'], true)), 'Cancel or close all purchase orders first.');
                if ($action === 'close') {
                    $this->ensure($orders->contains('status', 'closed'), 'A completed, closed purchase order is required.');
                }
                foreach ($record->solicitations()->with('award')->lockForUpdate()->get() as $solicitation) {
                    if ($solicitation->award && ! in_array($solicitation->award->status, ['closed', 'rejected', 'cancelled'], true)) {
                        $awardBefore = $solicitation->award->toArray();
                        $solicitation->award->update(['status' => $action === 'cancel' ? 'cancelled' : 'closed']);
                        $this->log($solicitation->award, $action, $data, $actor, $awardBefore);
                    }
                    $solicitationBefore = $solicitation->toArray();
                    $solicitation->update(['status' => $action === 'cancel' ? 'cancelled' : 'completed']);
                    $this->log($solicitation, $action, $data, $actor, $solicitationBefore);
                }
                $this->releaseUnused($record, $actor, $data['reason']);
                $record->status = $target;
                $record->lines()->update(['status' => $target]);
            }
            $record->save();
            $this->log($record, $action, $data, $actor, $before, ['lines']);

            return $record->fresh('lines');
        });
    }

    public function award(ProcurementAward $record, array $data, User $actor): ProcurementAward
    {
        return DB::transaction(function () use ($record, $data, $actor) {
            $record = ProcurementAward::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();
            $this->status($record, ['recommended']);
            $before = $record->toArray();
            $record->update(['status' => 'rejected', 'rejected_by' => $actor->id, 'rejected_at' => now(), 'rejection_reason' => $data['reason']]);
            $record->evaluation?->update(['recommended' => false, 'status' => 'evaluated']);
            $record->bid?->update(['status' => 'evaluated']);
            $this->log($record, 'reject', $data, $actor, $before);

            return $record->fresh();
        });
    }

    public function plan(ProcurementPlan $record, string $action, array $data, User $actor): ProcurementPlan
    {
        return DB::transaction(function () use ($record, $action, $data, $actor) {
            $record = ProcurementPlan::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();
            if ($action === 'close' && $record->status === 'closed') {
                return $record;
            }
            $this->status($record, ['draft', 'approved']);
            $before = $record->load('lines')->toArray();
            if ($action === 'amend') {
                $record->fill(array_intersect_key($data, array_flip(['title', 'description'])));
                if (! empty($data['line_id'])) {
                    $line = $record->lines()->whereKey($data['line_id'])->firstOrFail();
                    $this->ensure(! ProcurementRequisitionLine::query()->where('mda_id', $record->mda_id)->where('procurement_plan_line_id', $line->id)->exists(), 'This plan line is already used by a requisition. Add a new line instead.');
                    $line->fill(array_intersect_key($data, array_flip(['item_description', 'specification', 'quantity', 'unit', 'estimated_amount', 'estimated_unit_cost', 'notes'])))->save();
                }
                $record->summary = [...($record->summary ?? []), 'estimated_amount' => (float) $record->lines()->sum('estimated_amount')];
            } else {
                $requisitions = ProcurementRequisition::query()->where('mda_id', $record->mda_id)->where(function ($q) use ($record) {
                    $q->where('procurement_plan_id', $record->id)->orWhereHas('lines', fn ($lines) => $lines->whereIn('procurement_plan_line_id', $record->lines()->select('id')));
                });
                $this->ensure((clone $requisitions)->exists(), 'A plan must have completed requisitions before closure.');
                $this->ensure(! $requisitions->whereNotIn('status', ['closed', 'cancelled', 'rejected'])->exists(), 'Complete all requisitions before closing the plan.');
                $record->status = 'closed';
            }
            $record->save();
            $this->log($record, $action, $data, $actor, $before, ['lines']);

            return $record->fresh('lines');
        });
    }

    public function order(PurchaseOrder $record, string $action, array $data, User $actor): PurchaseOrder
    {
        return DB::transaction(function () use ($record, $action, $data, $actor) {
            // The requisition lock serializes order changes and shared commitment release.
            $requisition = ProcurementRequisition::query()->whereKey($record->procurement_requisition_id)->lockForUpdate()->firstOrFail();
            $record = PurchaseOrder::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();
            $target = $action === 'cancel' ? 'cancelled' : 'closed';
            if ($action !== 'amend' && $record->status === $target) {
                return $record;
            }
            $this->status($record, $action === 'close' ? ['received'] : ['issued', 'partially_received']);
            $before = $record->load('items')->toArray();
            if ($action === 'amend') {
                $this->ensure(! $record->receipts()->where('status', 'draft')->exists(), 'Post or cancel draft receipts before amending the order.');
                $record->fill(array_intersect_key($data, array_flip(['title', 'terms', 'expected_delivery_date'])));
                if (! empty($data['item_id'])) {
                    $item = $record->items()->whereKey($data['item_id'])->lockForUpdate()->firstOrFail();
                    $accepted = $item->receiptItems()->whereHas('receipt', fn ($q) => $q->where('status', 'posted'));
                    $quantityAccepted = (float) (clone $accepted)->sum('quantity_accepted');
                    $amountAccepted = (float) (clone $accepted)->sum('accepted_amount');
                    $quantity = (float) ($data['quantity'] ?? $item->quantity);
                    $price = (float) ($data['unit_price'] ?? $item->unit_price);
                    $this->ensure($quantity >= $quantityAccepted, 'Quantity cannot be less than goods already accepted.');
                    $this->ensure($quantityAccepted <= 0 || round($price, 2) === round((float) $item->unit_price, 2), 'Received goods cannot be repriced.');
                    $total = round($quantity * $price, 2);
                    $this->ensure($total >= $amountAccepted, 'Order value cannot be less than posted expenditure.');
                    $item->update(['quantity' => $quantity, 'unit_price' => $price, 'total_amount' => $total, 'status' => $quantityAccepted >= $quantity ? 'received' : ($quantityAccepted > 0 ? 'partially_received' : 'ordered')]);
                    $record->total_amount = $record->items()->sum('total_amount');
                    $this->ensure((float) $record->total_amount <= (float) $record->award->award_amount, 'Amendments cannot exceed the approved award. Obtain a revised award first.');
                    foreach ($record->items()->whereNotNull('budget_commitment_id')->get()->pluck('budget_commitment_id')->unique()->sort() as $id) {
                        $commitment = BudgetCommitment::query()->where('mda_id', $record->mda_id)->whereKey($id)->lockForUpdate()->firstOrFail();
                        $this->ensure($this->reserved($requisition, $commitment) <= $this->ledger->outstandingCommitment($commitment) + 0.001, 'The amended order exceeds the remaining budget commitment.');
                    }
                    $record->status = $record->items()->where('status', '!=', 'received')->exists() ? ($quantityAccepted > 0 || $record->status === 'partially_received' ? 'partially_received' : 'issued') : 'received';
                }
            } else {
                if ($action === 'close') {
                    $this->ensure(! $record->receipts()->where('status', 'draft')->exists(), 'Post or cancel draft receipts before closing the order.');
                    foreach ($record->items()->get() as $item) {
                        $quantity = (float) $item->receiptItems()->whereHas('receipt', fn ($q) => $q->where('status', 'posted'))->sum('quantity_accepted');
                        $this->ensure(round($quantity, 4) >= round((float) $item->quantity, 4), 'All ordered quantities must be accepted before closure.');
                    }
                } else {
                    foreach ($record->receipts()->where('status', 'draft')->lockForUpdate()->get() as $receipt) {
                        $receiptBefore = $receipt->toArray();
                        $receipt->update(['status' => 'cancelled']);
                        $this->log($receipt, 'cancel', $data, $actor, $receiptBefore);
                    }
                }
                $record->status = $target;
                $record->save();
                $record->items()->update(['status' => $target]);
                $awardBefore = $record->award->toArray();
                $record->award->update(['status' => $action === 'cancel' ? 'cancelled' : 'closed']);
                $this->log($record->award, $action, $data, $actor, $awardBefore);
                $this->releaseUnused($requisition, $actor, $data['reason']);
            }
            $record->save();
            $this->log($record, $action, $data, $actor, $before, ['items']);

            return $record->fresh('items');
        });
    }

    public function cancelReceipt(ProcurementReceipt $record, array $data, User $actor): Model
    {
        return DB::transaction(function () use ($record, $data, $actor) {
            $record = $record->newQuery()->whereKey($record->id)->lockForUpdate()->firstOrFail();
            if ($record->status === 'cancelled') {
                return $record;
            }
            $this->status($record, ['draft']);
            $before = $record->toArray();
            $record->update(['status' => 'cancelled']);
            $this->log($record, 'cancel', $data, $actor, $before);

            return $record;
        });
    }

    public function history(Model $record): array
    {
        // Authorization is performed on the bound MDA-owned parent before this lookup.
        return AuditLog::query()->where('auditable_type', $record::class)->where('auditable_id', $record->id)
            ->with('actor:id,name')->orderByDesc('id')->get()->map(fn ($log) => [
                'id' => $log->id, 'action' => $log->context['action'] ?? $log->context['source'] ?? $log->event_code,
                'reason' => $log->context['reason'] ?? null, 'actor' => $log->actor?->name,
                'occurred_at' => $log->occurred_at, 'before' => $log->before_values, 'after' => $log->after_values,
            ])->all();
    }

    private function releaseUnused(ProcurementRequisition $requisition, User $actor, string $reason): void
    {
        $ids = $requisition->lines()->whereNotNull('budget_commitment_id')->pluck('budget_commitment_id')->unique()->sort();
        // Unordered sourcing still needs its approved envelope; release it at requisition closure/cancellation.
        $pendingSourcing = $requisition->solicitations()->whereIn('status', ['draft', 'published', 'closed', 'awarded'])
            ->whereDoesntHave('award.purchaseOrder')->exists();
        if ($pendingSourcing) {
            return;
        }
        foreach ($ids as $id) {
            $commitment = BudgetCommitment::query()->where('mda_id', $requisition->mda_id)->whereKey($id)->lockForUpdate()->firstOrFail();
            $unused = round($this->ledger->outstandingCommitment($commitment) - $this->reserved($requisition, $commitment), 2);
            if ($unused > 0) {
                $this->ledger->releaseCommitment($commitment, $unused, $actor, $reason);
            }
        }
    }

    private function reserved(ProcurementRequisition $requisition, BudgetCommitment $commitment): float
    {
        return round(PurchaseOrderItem::query()->where('mda_id', $requisition->mda_id)->where('budget_commitment_id', $commitment->id)
            ->whereHas('purchaseOrder', fn ($q) => $q->where('procurement_requisition_id', $requisition->id)->whereNotIn('status', ['cancelled', 'closed']))
            ->get()->sum(function ($item) {
                $spent = $item->receiptItems()->whereHas('receipt', fn ($q) => $q->where('status', 'posted'))->sum('accepted_amount');

                return max(0, (float) $item->total_amount - (float) $spent);
            }), 2);
    }

    private function log(Model $record, string $action, array $data, User $actor, array $before, array $relations = []): void
    {
        $this->audit->log('procurement.workflow.'.$action, $record, $before, $record->fresh($relations)->toArray(), [
            'mda_id' => $record->mda_id, 'action' => $action, 'reason' => $data['reason'], 'actor_id' => $actor->id,
        ]);
    }

    private function status(Model $record, array $allowed): void
    {
        $this->ensure(in_array($record->status, $allowed, true), 'This action is not allowed while the record is '.$record->status.'.');
    }

    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['workflow' => $message]);
        }
    }
}
