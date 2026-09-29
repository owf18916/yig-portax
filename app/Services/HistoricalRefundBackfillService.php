<?php

namespace App\Services;

use App\Models\AppealDecision;
use App\Models\ObjectionDecision;
use App\Models\RefundProcess;
use App\Models\SkpRecord;
use App\Models\SupremeCourtDecision;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class HistoricalRefundBackfillService
{
    public const APPLY_LIMIT = 500;

    private const STAGES = [
        4 => ['table' => 'skp_records', 'type' => SkpRecord::class, 'source' => RefundProcess::STAGE_SOURCE_SKP],
        7 => ['table' => 'objection_decisions', 'type' => ObjectionDecision::class, 'source' => RefundProcess::STAGE_SOURCE_OBJECTION],
        10 => ['table' => 'appeal_decisions', 'type' => AppealDecision::class, 'source' => RefundProcess::STAGE_SOURCE_APPEAL],
        12 => ['table' => 'supreme_court_decisions', 'type' => SupremeCourtDecision::class, 'source' => RefundProcess::STAGE_SOURCE_SUPREME_COURT],
    ];

    /**
     * Discover all evidence-backed pairs with one bounded set of queries.
     *
     * @return array{rows: array<int, array<string, mixed>>, counts: array<string, int>}
     */
    public function discover(?string $caseNumber = null, ?int $stage = null): array
    {
        if ($stage !== null && ! isset(self::STAGES[$stage])) {
            throw new RuntimeException('Stage must be one of 4, 7, 10, or 12.');
        }

        $stages = $stage === null ? array_keys(self::STAGES) : [$stage];
        $caseScopeIds = null;
        if ($caseNumber !== null) {
            $caseScopeIds = DB::table('tax_cases')->where('case_number', $caseNumber)->pluck('id')->all();
            if ($caseScopeIds === []) {
                return $this->result([]);
            }
        }

        // This small signal query lets history-only contradictions enter review without
        // hydrating every TaxCase or every decision record in production.
        $historySignals = DB::table('workflow_histories')
            ->whereIn('stage_id', $stages)
            ->where('status', 'submitted')
            ->where('decision_value', 'like', '%create_refund%')
            ->when($caseScopeIds !== null, fn ($query) => $query->whereIn('tax_case_id', $caseScopeIds))
            ->get(['tax_case_id', 'stage_id', 'decision_value'])
            ->filter(fn ($row) => $this->historyRefundValue($row) === true);
        $historySignalCases = $historySignals
            ->groupBy('stage_id')
            ->map(fn ($rows) => $rows->pluck('tax_case_id')->unique()->all());

        $decisions = collect();

        foreach (self::STAGES as $stageId => $config) {
            if ($stage !== null && $stage !== $stageId) {
                continue;
            }

            $signalCaseIds = $historySignalCases->get($stageId, []);
            $rows = DB::table($config['table'])
                ->when($caseScopeIds !== null, fn ($query) => $query->whereIn('tax_case_id', $caseScopeIds))
                ->where(function ($query) use ($signalCaseIds) {
                    $query->where('create_refund', true);
                    if ($signalCaseIds !== []) {
                        $query->orWhereIn('tax_case_id', $signalCaseIds);
                    }
                })
                ->select(array_values(array_filter([
                    'id', 'tax_case_id', 'create_refund', 'refund_amount', 'deleted_at',
                    $stageId === 4 ? null : 'status',
                ])))
                ->get();

            foreach ($rows as $row) {
                $row->origin_stage = $stageId;
                $row->decision_type = $config['type'];
                $row->stage_source = $config['source'];
                $decisions->push($row);
            }
        }

        $signalKeys = $historySignals->map(fn ($row) => $this->key((int) $row->tax_case_id, (int) $row->stage_id));
        $decisionGroups = $decisions->groupBy(fn ($row) => $this->key((int) $row->tax_case_id, (int) $row->origin_stage));
        $keys = collect($decisionGroups)
            ->filter(fn ($rows) => $rows->contains(fn ($row) => (bool) $row->create_refund))
            ->keys()
            ->merge($signalKeys)
            ->unique()
            ->sort()
            ->values();

        if ($keys->isEmpty()) {
            return $this->result([]);
        }

        $caseIds = $keys->map(fn ($key) => (int) explode(':', $key)[0])->unique()->values()->all();
        $taxCases = DB::table('tax_cases')
            ->whereIn('id', $caseIds)
            ->select(['id', 'case_number', 'deleted_at', 'current_stage', 'case_status_id'])
            ->get()
            ->keyBy('id');

        $histories = DB::table('workflow_histories')
            ->whereIn('tax_case_id', $caseIds)
            ->whereIn('stage_id', array_merge($stages, [13, 14, 15]))
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'tax_case_id', 'stage_id', 'status', 'decision_value', 'user_id', 'created_at']);

        $decisionHistories = $histories
            ->whereIn('stage_id', $stages)
            ->where('status', 'submitted')
            ->groupBy(fn ($row) => $this->key((int) $row->tax_case_id, (int) $row->stage_id));
        $legacyCaseIds = $histories->whereIn('stage_id', [13, 14, 15])->pluck('tax_case_id')->map(fn ($id) => (int) $id)->flip();

        $refunds = DB::table('refund_processes')
            ->whereIn('tax_case_id', $caseIds)
            ->get(['id', 'tax_case_id', 'stage_id', 'stage_source', 'triggered_by_decision_id', 'triggered_by_decision_type', 'deleted_at', 'status'])
            ->groupBy('tax_case_id');

        $transferCaseIds = DB::table('bank_transfer_requests as transfers')
            ->join('refund_processes as refunds', 'refunds.id', '=', 'transfers.refund_process_id')
            ->whereIn('refunds.tax_case_id', $caseIds)
            ->pluck('refunds.tax_case_id')
            ->map(fn ($id) => (int) $id)
            ->flip();

        $rows = [];
        foreach ($keys as $key) {
            [$taxCaseId, $stageId] = array_map('intval', explode(':', $key));
            $case = $taxCases->get($taxCaseId);
            if (! $case) {
                continue;
            }
            $pairDecisions = $decisionGroups->get($key, collect());
            $activeDecisions = $pairDecisions->whereNull('deleted_at')->values();
            $pairHistories = $decisionHistories->get($key, collect())->values();
            $pairRefunds = $refunds->get($taxCaseId, collect());

            $rows[] = $this->classify(
                $case,
                $stageId,
                $activeDecisions,
                $pairHistories,
                $pairRefunds,
                $legacyCaseIds->has($taxCaseId),
                $transferCaseIds->has($taxCaseId),
            );
        }

        usort($rows, fn ($a, $b) => [$a['tax_case_id'], $a['stage_id']] <=> [$b['tax_case_id'], $b['stage_id']]);

        return $this->result($rows);
    }

    /**
     * Apply one reviewed manifest atomically. Any changed classification aborts the batch.
     *
     * @param  array<int, array<string, mixed>>  $manifest
     * @return array{inserted_ids: array<int, int>, verification: string}
     */
    public function apply(array $manifest): array
    {
        if (count($manifest) > self::APPLY_LIMIT) {
            throw new RuntimeException(sprintf(
                'Apply refused: %d candidates exceed the atomic safety limit of %d. Re-run with --case or --stage.',
                count($manifest),
                self::APPLY_LIMIT,
            ));
        }

        if ($manifest === []) {
            return ['inserted_ids' => [], 'verification' => 'passed (nothing to insert)'];
        }

        return DB::transaction(function () use ($manifest) {
            $caseIds = collect($manifest)->pluck('tax_case_id')->unique()->sort()->values()->all();
            DB::table('tax_cases')->whereIn('id', $caseIds)->orderBy('id')->lockForUpdate()->get(['id']);

            foreach (collect($manifest)->groupBy('stage_id')->sortKeys() as $stageId => $stageManifest) {
                $config = self::STAGES[(int) $stageId];
                DB::table($config['table'])
                    ->whereIn('id', $stageManifest->pluck('decision_id')->all())
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get(['id']);
            }

            DB::table('refund_processes')
                ->whereIn('tax_case_id', $caseIds)
                ->orderBy('tax_case_id')
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id']);

            $fresh = $this->discover();
            $freshByKey = collect($fresh['rows'])->keyBy(fn ($row) => $this->key($row['tax_case_id'], $row['stage_id']));
            foreach ($manifest as $candidate) {
                $current = $freshByKey->get($this->key($candidate['tax_case_id'], $candidate['stage_id']));
                if (! $current || $current['result'] !== 'ELIGIBLE' || $this->signature($current) !== $this->signature($candidate)) {
                    throw new RuntimeException(sprintf(
                        'Candidate %d/stage %d changed during apply; the entire batch was rolled back.',
                        $candidate['tax_case_id'],
                        $candidate['stage_id'],
                    ));
                }
            }

            $nextSequences = DB::table('refund_processes')
                ->whereIn('tax_case_id', $caseIds)
                ->selectRaw('tax_case_id, COALESCE(MAX(sequence_number), 0) AS max_sequence')
                ->groupBy('tax_case_id')
                ->pluck('max_sequence', 'tax_case_id')
                ->map(fn ($value) => (int) $value);

            $insertedIds = [];
            foreach ($manifest as $candidate) {
                $sequence = ($nextSequences[$candidate['tax_case_id']] ?? 0) + 1;
                $nextSequences[$candidate['tax_case_id']] = $sequence;
                $timestamp = CarbonImmutable::parse($candidate['source_timestamp']);

                $insertedIds[] = (int) DB::table('refund_processes')->insertGetId([
                    'tax_case_id' => $candidate['tax_case_id'],
                    'stage_id' => $candidate['stage_id'],
                    'refund_number' => sprintf('HIST-%d-%d-%d', $candidate['stage_id'], $candidate['tax_case_id'], $candidate['decision_id']),
                    'refund_amount' => $candidate['refund_amount'],
                    'refund_method' => 'bank_transfer',
                    'refund_status' => 'pending',
                    'status' => 'draft',
                    'stage_source' => $candidate['stage_source'],
                    'sequence_number' => $sequence,
                    'triggered_by_decision_id' => $candidate['decision_id'],
                    'triggered_by_decision_type' => $candidate['decision_type'],
                    'submitted_by' => $candidate['submitted_by'],
                    'submitted_at' => $timestamp,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);
            }

            $this->verify($manifest, $insertedIds);

            return ['inserted_ids' => $insertedIds, 'verification' => 'passed'];
        }, 3);
    }

    private function classify(object $case, int $stage, Collection $decisions, Collection $histories, Collection $refunds, bool $hasLegacyHistory, bool $hasTransfer): array
    {
        $base = [
            'tax_case_id' => (int) $case->id,
            'case_number' => $case->case_number,
            'stage_id' => $stage,
            'decision_id' => null,
            'decision_type' => self::STAGES[$stage]['type'],
            'stage_source' => self::STAGES[$stage]['source'],
            'history_id' => null,
            'refund_amount' => null,
            'submitted_by' => null,
            'source_timestamp' => null,
            'existing_refund_status' => 'none',
            'result' => 'MANUAL REVIEW',
            'reason' => '',
            'collision' => false,
        ];

        if ($case->deleted_at !== null) {
            return $this->manual($base, 'TaxCase is soft-deleted.');
        }
        if ($decisions->count() !== 1) {
            return $this->manual($base, sprintf('Expected exactly one active decision record; found %d.', $decisions->count()));
        }

        $decision = $decisions->first();
        $base['decision_id'] = (int) $decision->id;
        $base['refund_amount'] = $this->decimal($decision->refund_amount);

        if (! (bool) $decision->create_refund) {
            return $this->manual($base, 'Submitted history requests a Refund but the decision record does not.');
        }
        if (property_exists($decision, 'status') && ! in_array($decision->status, ['submitted', 'approved'], true)) {
            return $this->manual($base, 'Decision record is draft-only or is not in a submitted/approved status.');
        }

        $parsed = $histories->map(fn ($history) => ['row' => $history, 'value' => $this->historyRefundValue($history)]);
        if ($parsed->contains(fn ($item) => $item['value'] === 'unparseable')) {
            return $this->manual($base, 'Submitted decision history contains an unparseable decision_value.');
        }
        $explicit = $parsed->filter(fn ($item) => is_bool($item['value']))->values();
        if ($explicit->isEmpty()) {
            return $this->manual($base, 'No submitted same-stage history explicitly records create_refund.');
        }
        $latest = $explicit->last();
        $base['history_id'] = (int) $latest['row']->id;
        $base['submitted_by'] = (int) $latest['row']->user_id;
        $base['source_timestamp'] = $latest['row']->created_at;
        if ($latest['value'] !== true) {
            return $this->manual($base, 'Later submitted same-stage history contradicts Refund intent.');
        }

        $sameStage = $refunds->where('stage_id', $stage);
        if ($sameStage->isNotEmpty()) {
            $refund = $sameStage->sortByDesc('id')->first();
            $base['existing_refund_status'] = $refund->deleted_at === null
                ? "active #{$refund->id} ({$refund->status})"
                : "soft-deleted #{$refund->id} ({$refund->status})";
            $matching = $refund->deleted_at === null
                && (int) $refund->triggered_by_decision_id === (int) $decision->id
                && $refund->triggered_by_decision_type === self::STAGES[$stage]['type'];
            if ($sameStage->count() === 1 && $matching) {
                $base['result'] = 'SKIPPED';
                $base['reason'] = 'Canonical RefundProcess already satisfies this decision stage.';

                return $base;
            }

            return $this->manual($base, 'Same-stage active or soft-deleted RefundProcess collision.', true);
        }

        $crossStageTrigger = $refunds->contains(fn ($refund) => (int) $refund->triggered_by_decision_id === (int) $decision->id
            && $refund->triggered_by_decision_type === self::STAGES[$stage]['type']
        );
        if ($crossStageTrigger) {
            return $this->manual($base, 'Another-stage RefundProcess points to this triggering decision.', true);
        }
        if ($hasLegacyHistory) {
            return $this->manual($base, 'Unexplained Stage 13-15 workflow history exists for the TaxCase.');
        }
        if ($hasTransfer) {
            return $this->manual($base, 'Bank-transfer evidence exists without deterministic linkage to this missing RefundProcess.');
        }

        $base['result'] = 'ELIGIBLE';
        $base['reason'] = 'Decision and latest submitted history explicitly request Refund; no collision found.';

        return $base;
    }

    private function historyRefundValue(object $history): bool|string|null
    {
        if ($history->decision_value === null || trim((string) $history->decision_value) === '') {
            return null;
        }

        try {
            $value = json_decode((string) $history->decision_value, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return 'unparseable';
        }

        if (! is_array($value) || ! array_key_exists('create_refund', $value) || ! is_bool($value['create_refund'])) {
            return 'unparseable';
        }

        return $value['create_refund'];
    }

    private function verify(array $manifest, array $insertedIds): void
    {
        if (count($insertedIds) !== count($manifest)) {
            throw new RuntimeException('Inserted RefundProcess count does not match the reviewed manifest.');
        }

        $inserted = DB::table('refund_processes')->whereIn('id', $insertedIds)->get()->keyBy('id');
        foreach (array_values($manifest) as $index => $candidate) {
            $refund = $inserted->get($insertedIds[$index]);
            $valid = $refund
                && (int) $refund->tax_case_id === $candidate['tax_case_id']
                && (int) $refund->stage_id === $candidate['stage_id']
                && (int) $refund->triggered_by_decision_id === $candidate['decision_id']
                && $refund->triggered_by_decision_type === $candidate['decision_type']
                && $this->decimal($refund->refund_amount) === $candidate['refund_amount']
                && $refund->refund_status === 'pending'
                && $refund->status === 'draft';
            if (! $valid) {
                throw new RuntimeException('Inserted RefundProcess verification failed; the entire batch was rolled back.');
            }
        }

        $duplicates = DB::table('refund_processes')
            ->whereIn('tax_case_id', collect($manifest)->pluck('tax_case_id')->all())
            ->whereIn('stage_id', collect($manifest)->pluck('stage_id')->all())
            ->select(['tax_case_id', 'stage_id'])
            ->groupBy('tax_case_id', 'stage_id')
            ->havingRaw('COUNT(*) > 1')
            ->limit(1)
            ->get()
            ->isNotEmpty();
        if ($duplicates) {
            throw new RuntimeException('Duplicate stage-scoped RefundProcess verification failed; the entire batch was rolled back.');
        }
    }

    private function signature(array $row): string
    {
        return implode('|', [
            $row['tax_case_id'], $row['stage_id'], $row['decision_id'], $row['decision_type'],
            $row['history_id'], $row['refund_amount'], $row['submitted_by'], $row['source_timestamp'],
        ]);
    }

    private function decimal(mixed $value): string
    {
        if ($value === null) {
            return '0.00';
        }

        $value = (string) $value;
        if (preg_match('/^(-?\d+)(?:\.(\d+))?$/', $value, $matches) !== 1) {
            throw new RuntimeException('Historical refund amount is not a valid decimal.');
        }

        return $matches[1].'.'.str_pad(substr($matches[2] ?? '', 0, 2), 2, '0');
    }

    private function manual(array $base, string $reason, bool $collision = false): array
    {
        $base['reason'] = $reason;
        $base['collision'] = $collision;

        return $base;
    }

    private function key(int $taxCaseId, int $stage): string
    {
        return $taxCaseId.':'.$stage;
    }

    private function result(array $rows): array
    {
        $collection = collect($rows);

        return [
            'rows' => $rows,
            'counts' => [
                'candidates' => $collection->where('result', 'ELIGIBLE')->count(),
                'skipped' => $collection->where('result', 'SKIPPED')->count(),
                'manual_review' => $collection->where('result', 'MANUAL REVIEW')->count(),
                'collisions' => $collection->where('collision', true)->count(),
                'would_insert' => $collection->where('result', 'ELIGIBLE')->count(),
            ],
        ];
    }
}
