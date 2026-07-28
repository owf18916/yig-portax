<?php

namespace App\Services\TaxWorkflowMatrix;

use App\Models\RefundProcess;
use App\Models\TaxCase;

class RefundAggregateResolver
{
    public function __construct(private StageRoutingResolver $routingResolver)
    {
    }

    public function resolve(TaxCase $taxCase): array
    {
        $refunds = $taxCase->refundProcesses ?? collect();
        $count = $refunds->count();
        $dataQuality = [];

        if ($count === 0) {
            $intent = $this->routingResolver->hasRefundIntent($taxCase);
            if (!empty($intent)) {
                return [
                    'status' => 'eligible',
                    'label' => 'Eligible',
                    'count' => 0,
                    'clickable' => true,
                    'action' => [
                        'type' => 'route',
                        'name' => 'RefundStage1Form',
                        'params' => ['id' => $taxCase->id],
                        'query' => [],
                    ],
                    'reason' => null,
                    'source' => $intent,
                    'data_quality' => [],
                ];
            }

            return $this->cell('not_available', 'Not Available', 0, false, null, 'No refund process or explicit refund intent exists.', [], []);
        }

        $states = $refunds->map(function (RefundProcess $refund) use (&$dataQuality) {
            $status = strtolower((string) ($refund->refund_status ?: $refund->status ?: ''));
            $latestTransfer = $refund->bankTransferRequests
                ->sortByDesc(fn ($transfer) => sprintf('%s-%010d', optional($transfer->created_at)->format('YmdHis') ?? '', $transfer->id))
                ->first();

            if (!in_array((int) $refund->stage_id, RefundProcess::VALID_STAGE_IDS, true)) {
                $dataQuality[] = ['code' => 'orphaned_refund_origin', 'refund_id' => $refund->id, 'stage_id' => $refund->stage_id];
            }

            if (in_array($status, ['completed', 'approved'], true)) {
                return 'completed';
            }

            if (!$latestTransfer) {
                return 'input_required';
            }

            $transferStatus = strtolower((string) $latestTransfer->transfer_status);
            if ($transferStatus === 'completed' && $latestTransfer->received_date) {
                return 'completed';
            }

            if (in_array($transferStatus, ['pending', 'processing'], true)) {
                return 'in_progress';
            }

            $dataQuality[] = ['code' => 'refund_progress_conflict', 'refund_id' => $refund->id, 'transfer_status' => $transferStatus];
            return 'input_required';
        });

        if ($dataQuality) {
            $status = 'input_required';
        } elseif ($states->contains('input_required')) {
            $status = 'input_required';
        } elseif ($states->contains('in_progress')) {
            $status = 'in_progress';
        } elseif ($states->every(fn ($state) => $state === 'completed')) {
            $status = 'completed';
        } else {
            $status = 'not_available';
        }

        $action = null;
        $clickable = !in_array($status, ['not_available'], true);
        if ($clickable && $count === 1) {
            $refund = $refunds->first();
            $stage = $this->currentRefundStageFromLoaded($refund);
            $action = [
                'type' => 'route',
                'name' => "RefundStage{$stage}FormWithId",
                'params' => ['id' => $taxCase->id, 'refundId' => $refund->id],
                'query' => [],
            ];
        } elseif ($clickable) {
            $action = [
                'type' => 'route',
                'name' => 'TaxCaseDetail',
                'params' => ['id' => $taxCase->id],
                'query' => ['section' => 'refunds'],
            ];
        }

        return $this->cell($status, $this->aggregateLabel($status, $count), $count, $clickable, $action, null, [], $dataQuality);
    }

    private function currentRefundStageFromLoaded(RefundProcess $refund): int
    {
        $latestTransfer = $refund->bankTransferRequests
            ->sortByDesc(fn ($transfer) => sprintf('%s-%010d', optional($transfer->created_at)->format('YmdHis') ?? '', $transfer->id))
            ->first();

        if (!$latestTransfer) {
            return 1;
        }
        if ($latestTransfer->transfer_status === 'completed' && $latestTransfer->received_date) {
            return 4;
        }
        if ($latestTransfer->transfer_status === 'processing' && $latestTransfer->instruction_received_date) {
            return 3;
        }
        if (in_array($latestTransfer->transfer_status, ['pending', 'processing'], true)) {
            return 2;
        }

        return 1;
    }

    private function aggregateLabel(string $status, int $count): string
    {
        $label = match ($status) {
            'eligible' => 'Eligible',
            'input_required' => 'Input Required',
            'in_progress' => 'In Progress',
            'completed' => 'Completed',
            default => 'Not Available',
        };

        return $count > 0 ? "{$count} Refund" . ($count === 1 ? '' : 's') . " · {$label}" : $label;
    }

    private function cell(string $status, string $label, int $count, bool $clickable, ?array $action, ?string $reason, array $source, array $dataQuality): array
    {
        return [
            'status' => $status,
            'label' => $label,
            'count' => $count,
            'clickable' => $clickable,
            'action' => $action,
            'reason' => $reason,
            'source' => $source,
            'data_quality' => $dataQuality,
        ];
    }
}
