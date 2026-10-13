<?php

namespace App\Domain\Procurement\Services;

use App\Domain\Procurement\Models\ProcurementAward;
use App\Domain\Procurement\Models\ProcurementBid;
use App\Domain\Procurement\Models\ProcurementEvaluation;
use App\Domain\Procurement\Models\ProcurementSolicitation;
use App\Domain\Procurement\Models\PurchaseOrder;
use App\Domain\Procurement\Models\PurchaseOrderItem;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProcurementAwardService
{
    public function __construct(private AuditLogService $auditLogService) {}

    public function evaluateBid(ProcurementBid $bid, array $data, ?User $actor = null): ProcurementEvaluation
    {
        return DB::transaction(function () use ($bid, $data, $actor): ProcurementEvaluation {
            $bid = ProcurementBid::query()->with(['solicitation', 'vendor'])->whereKey($bid->id)->lockForUpdate()->firstOrFail();

            if (! in_array($bid->status, ['submitted', 'evaluated', 'recommended'], true)) {
                throw ValidationException::withMessages(['bid' => 'Only submitted bids can be evaluated.']);
            }

            $technical = array_key_exists('technical_score', $data) ? (float) $data['technical_score'] : null;
            $financial = array_key_exists('financial_score', $data) ? (float) $data['financial_score'] : null;
            $total = array_key_exists('total_score', $data)
                ? (float) $data['total_score']
                : $this->deriveTotalScore($technical, $financial);

            $evaluation = ProcurementEvaluation::query()->firstOrNew([
                'procurement_solicitation_id' => $bid->procurement_solicitation_id,
                'procurement_bid_id' => $bid->id,
            ]);
            $before = $evaluation->exists ? $evaluation->toArray() : [];
            $evaluation->fill([
                'mda_id' => $bid->mda_id,
                'vendor_id' => $bid->vendor_id,
                'technical_score' => $technical,
                'financial_score' => $financial,
                'total_score' => $total,
                'status' => $data['status'] ?? 'evaluated',
                'recommended' => (bool) ($data['recommended'] ?? false),
                'rank' => $data['rank'] ?? $evaluation->rank,
                'recommendation_notes' => $data['recommendation_notes'] ?? $evaluation->recommendation_notes,
                'evaluated_by' => $actor?->id ?? $evaluation->evaluated_by,
                'evaluated_at' => now(),
                'criteria_scores' => $data['criteria_scores'] ?? $evaluation->criteria_scores,
                'metadata' => [
                    ...($evaluation->metadata ?? []),
                    ...($data['metadata'] ?? []),
                ],
            ]);
            $evaluation->save();

            $bidBefore = $bid->toArray();
            $bid->forceFill([
                'technical_score' => $technical,
                'financial_score' => $financial,
                'total_score' => $total,
                'status' => $evaluation->recommended ? 'recommended' : 'evaluated',
            ])->save();

            if ($before === []) {
                $this->auditLogService->log('procurement.bid_evaluated', $evaluation, [], $evaluation->toArray(), [
                    'mda_id' => $bid->mda_id,
                    'procurement_bid_id' => $bid->id,
                ]);
            } else {
                $this->auditLogService->log('procurement.bid_evaluation_updated', $evaluation, $before, $evaluation->toArray(), [
                    'mda_id' => $bid->mda_id,
                    'procurement_bid_id' => $bid->id,
                ]);
            }

            $this->auditLogService->logUpdated($bid, $bidBefore, ['source' => 'procurement.bid_score_synced']);

            return $evaluation->fresh(['bid.vendor', 'solicitation']);
        });
    }

    public function recommendAward(ProcurementEvaluation $evaluation, ?User $actor = null, ?string $summary = null): ProcurementAward
    {
        return DB::transaction(function () use ($evaluation, $actor, $summary): ProcurementAward {
            $evaluation = ProcurementEvaluation::query()
                ->with(['bid', 'solicitation'])
                ->whereKey($evaluation->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($evaluation->status, ['evaluated', 'recommended'], true)) {
                throw ValidationException::withMessages(['evaluation' => 'Only evaluated bids can be recommended for award.']);
            }

            $this->clearPreviousRecommendations($evaluation);

            $evaluationBefore = $evaluation->toArray();
            $evaluation->forceFill([
                'recommended' => true,
                'status' => 'recommended',
                'recommendation_notes' => $summary ?? $evaluation->recommendation_notes,
            ])->save();

            $bidBefore = $evaluation->bid->toArray();
            $evaluation->bid->forceFill(['status' => 'recommended'])->save();

            $award = ProcurementAward::query()->firstOrNew([
                'procurement_solicitation_id' => $evaluation->procurement_solicitation_id,
            ]);
            $before = $award->exists ? $award->toArray() : [];
            $award->fill([
                'mda_id' => $evaluation->mda_id,
                'procurement_bid_id' => $evaluation->procurement_bid_id,
                'vendor_id' => $evaluation->vendor_id,
                'procurement_evaluation_id' => $evaluation->id,
                'award_no' => $award->award_no ?? $this->nextAwardNumber($evaluation->mda_id),
                'award_amount' => $evaluation->bid->quoted_amount,
                'currency' => $evaluation->bid->currency,
                'status' => 'recommended',
                'recommendation_summary' => $summary ?? $evaluation->recommendation_notes,
                'recommended_by' => $actor?->id ?? $award->recommended_by,
                'recommended_at' => now(),
            ]);
            $award->save();

            $this->auditLogService->logUpdated($evaluation, $evaluationBefore, ['source' => 'procurement.award_recommendation_marked']);
            $this->auditLogService->logUpdated($evaluation->bid, $bidBefore, ['source' => 'procurement.bid_recommended']);
            $this->auditLogService->log(
                $before === [] ? 'procurement.award_recommended' : 'procurement.award_recommendation_updated',
                $award,
                $before,
                $award->toArray(),
                ['mda_id' => $award->mda_id, 'procurement_solicitation_id' => $award->procurement_solicitation_id],
            );

            return $award->fresh(['vendor', 'bid', 'evaluation']);
        });
    }

    public function approveAward(ProcurementAward $award, ?User $actor = null): ProcurementAward
    {
        return DB::transaction(function () use ($award, $actor): ProcurementAward {
            $award = ProcurementAward::query()->with('solicitation')->whereKey($award->id)->lockForUpdate()->firstOrFail();

            if ($award->status === 'approved') {
                return $award->fresh(['vendor', 'bid', 'purchaseOrder']);
            }

            if ($award->status !== 'recommended') {
                throw ValidationException::withMessages(['award' => 'Only recommended awards can be approved.']);
            }

            $before = $award->toArray();
            $award->forceFill([
                'status' => 'approved',
                'approved_by' => $actor?->id ?? $award->approved_by,
                'approved_at' => now(),
            ])->save();

            $solicitationBefore = $award->solicitation->toArray();
            $award->solicitation->forceFill(['status' => 'awarded'])->save();

            $this->auditLogService->log('procurement.award_approved', $award, $before, $award->toArray(), [
                'mda_id' => $award->mda_id,
                'approved_by' => $actor?->id,
            ]);
            $this->auditLogService->logUpdated($award->solicitation, $solicitationBefore, ['source' => 'procurement.solicitation_awarded']);

            return $award->fresh(['vendor', 'bid', 'purchaseOrder']);
        });
    }

    public function generatePurchaseOrder(ProcurementAward $award, ?User $actor = null, array $data = []): PurchaseOrder
    {
        return DB::transaction(function () use ($award, $actor, $data): PurchaseOrder {
            $award = ProcurementAward::query()
                ->with(['solicitation.requisition.lines', 'vendor'])
                ->whereKey($award->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($award->status !== 'approved') {
                throw ValidationException::withMessages(['award' => 'Only approved awards can generate purchase orders.']);
            }

            $po = PurchaseOrder::query()->firstOrNew(['procurement_award_id' => $award->id]);
            $before = $po->exists ? $po->toArray() : [];
            $requisition = $award->solicitation->requisition;
            $po->fill([
                'mda_id' => $award->mda_id,
                'procurement_solicitation_id' => $award->procurement_solicitation_id,
                'procurement_requisition_id' => $requisition?->id,
                'vendor_id' => $award->vendor_id,
                'po_no' => $po->po_no ?? $data['po_no'] ?? $this->nextPoNumber($award->mda_id),
                'title' => $data['title'] ?? $award->solicitation->title,
                'total_amount' => $award->award_amount,
                'currency' => $award->currency,
                'status' => $data['status'] ?? 'issued',
                'order_date' => $data['order_date'] ?? now()->toDateString(),
                'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
                'terms' => $data['terms'] ?? null,
                'prepared_by' => $actor?->id ?? $po->prepared_by,
                'approved_by' => $actor?->id ?? $po->approved_by,
                'approved_at' => now(),
                'metadata' => [
                    ...($po->metadata ?? []),
                    ...($data['metadata'] ?? []),
                    'award_no' => $award->award_no,
                ],
            ]);
            $po->save();

            $this->syncPurchaseOrderItems($po, $award);

            $this->auditLogService->log(
                $before === [] ? 'procurement.purchase_order_generated' : 'procurement.purchase_order_updated',
                $po,
                $before,
                $po->fresh('items')->toArray(),
                ['mda_id' => $po->mda_id, 'procurement_award_id' => $award->id],
            );

            return $po->fresh(['items', 'vendor', 'award']);
        });
    }

    protected function syncPurchaseOrderItems(PurchaseOrder $po, ProcurementAward $award): void
    {
        $requisition = $award->solicitation->requisition;

        if (! $requisition || $requisition->lines->isEmpty()) {
            PurchaseOrderItem::query()->updateOrCreate(
                [
                    'purchase_order_id' => $po->id,
                    'procurement_requisition_line_id' => null,
                ],
                [
                    'mda_id' => $po->mda_id,
                    'budget_allocation_id' => null,
                    'budget_commitment_id' => null,
                    'item_description' => $award->solicitation->title,
                    'quantity' => 1,
                    'unit' => 'lot',
                    'unit_price' => $award->award_amount,
                    'total_amount' => $award->award_amount,
                    'status' => 'ordered',
                ]
            );

            return;
        }

        $requisitionTotal = max(0.01, (float) $requisition->lines->sum('estimated_amount'));
        foreach ($requisition->lines as $line) {
            $lineShare = round(((float) $line->estimated_amount / $requisitionTotal) * (float) $award->award_amount, 2);
            PurchaseOrderItem::query()->updateOrCreate(
                [
                    'purchase_order_id' => $po->id,
                    'procurement_requisition_line_id' => $line->id,
                ],
                [
                    'mda_id' => $po->mda_id,
                    'budget_allocation_id' => $line->budget_allocation_id,
                    'budget_commitment_id' => $line->budget_commitment_id,
                    'item_description' => $line->item_description,
                    'specification' => $line->specification,
                    'quantity' => $line->quantity,
                    'unit' => $line->unit,
                    'unit_price' => $line->quantity > 0 ? round($lineShare / (float) $line->quantity, 2) : $lineShare,
                    'total_amount' => $lineShare,
                    'status' => 'ordered',
                    'metadata' => [
                        'source_estimated_amount' => (float) $line->estimated_amount,
                        'award_amount' => (float) $award->award_amount,
                    ],
                ]
            );
        }

        $itemTotal = (float) PurchaseOrderItem::query()->where('purchase_order_id', $po->id)->sum('total_amount');
        if (round($itemTotal, 2) !== round((float) $award->award_amount, 2)) {
            $lastItem = PurchaseOrderItem::query()->where('purchase_order_id', $po->id)->latest('id')->first();
            if ($lastItem) {
                $lastItem->forceFill([
                    'total_amount' => round((float) $lastItem->total_amount + ((float) $award->award_amount - $itemTotal), 2),
                ])->save();
            }
        }
    }

    protected function clearPreviousRecommendations(ProcurementEvaluation $selected): void
    {
        ProcurementEvaluation::query()
            ->where('procurement_solicitation_id', $selected->procurement_solicitation_id)
            ->whereKeyNot($selected->id)
            ->update(['recommended' => false, 'status' => 'evaluated']);

        ProcurementBid::query()
            ->where('procurement_solicitation_id', $selected->procurement_solicitation_id)
            ->whereKeyNot($selected->procurement_bid_id)
            ->where('status', 'recommended')
            ->update(['status' => 'evaluated']);
    }

    protected function deriveTotalScore(?float $technical, ?float $financial): ?float
    {
        if ($technical === null && $financial === null) {
            return null;
        }

        return round(($technical ?? 0.0) + ($financial ?? 0.0), 2);
    }

    protected function nextAwardNumber(int $mdaId): string
    {
        $next = ProcurementAward::query()->where('mda_id', $mdaId)->count() + 1;

        return 'AWD-'.now()->format('Y').'-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }

    protected function nextPoNumber(int $mdaId): string
    {
        $next = PurchaseOrder::query()->where('mda_id', $mdaId)->count() + 1;

        return 'PO-'.now()->format('Y').'-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }
}
