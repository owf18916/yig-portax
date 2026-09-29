<?php

namespace App\Services\TaxWorkflowMatrix;

use App\Models\Entity;
use App\Models\FiscalYear;
use App\Models\Period;
use App\Models\TaxCase;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class TaxWorkflowMatrixService
{
    private const HISTORICAL_FLOOR = '2013-03';

    public function __construct(
        private StageStatusResolver $stageStatusResolver,
        private RefundAggregateResolver $refundAggregateResolver,
    ) {}

    public function build(User $user, array $filters): array
    {
        $entities = $this->authorizedEntities($user);
        if ($filters['entity_id']) {
            if (! $entities->pluck('id')->contains((int) $filters['entity_id'])) {
                throw new AuthorizationException('You are not authorized to view this entity.');
            }
            $entities = $entities->where('id', (int) $filters['entity_id'])->values();
        }

        $periods = $this->periods($filters);
        $keys = $this->paginateKeys($periods, $entities, $filters['page'], $filters['per_page']);
        $periodIds = $keys->getCollection()->pluck('period.id')->unique()->values();
        $entityIds = $keys->getCollection()->pluck('entity.id')->unique()->values();

        $cases = TaxCase::query()
            ->with([
                'entity',
                'period.fiscalYear',
                'workflowHistories',
                'status',
                'sp2Record',
                'sphpRecord',
                'skpRecord',
                'objectionSubmission',
                'spuhRecord',
                'objectionDecision',
                'appealSubmission',
                'appealExplanationRequest',
                'appealDecision',
                'supremeCourtSubmission',
                'supremeCourtDecision',
                'refundProcesses.bankTransferRequests',
            ])
            ->whereIn('entity_id', $entityIds)
            ->whereIn('period_id', $periodIds)
            ->where('case_type', $filters['tax_category'])
            ->get()
            ->keyBy(fn (TaxCase $case) => "{$case->entity_id}:{$case->period_id}");

        $rows = $keys->getCollection()->map(function (array $key) use ($cases, $filters, $entities) {
            /** @var Entity $entity */
            $entity = $key['entity'];
            /** @var Period $period */
            $period = $key['period'];
            $case = $cases->get("{$entity->id}:{$period->id}");

            return $case
                ? $this->caseRow($case, $period, $entity, $entities->count() > 1)
                : $this->missingCaseRow($filters['tax_category'], $period, $entity, $entities->count() > 1);
        });

        $rows = $this->filterRows($rows, $filters);

        return [
            'tax_category' => $filters['tax_category'],
            'columns' => StageDefinitions::columns(),
            'rows' => $rows->values(),
            'meta' => [
                'current_page' => $keys->currentPage(),
                'per_page' => $keys->perPage(),
                'total' => $keys->total(),
                'last_page' => $keys->lastPage(),
                'historical_floor' => self::HISTORICAL_FLOOR,
                'available_fiscal_years' => FiscalYear::orderByDesc('year')->get(['id', 'year']),
                'show_entity_column' => $entities->count() > 1,
            ],
        ];
    }

    private function authorizedEntities(User $user): Collection
    {
        $user->loadMissing('entity');

        if ($user->entity && strtoupper((string) $user->entity->entity_type) === 'HOLDING') {
            return Entity::query()->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name', 'entity_type']);
        }

        if (! $user->entity_id) {
            return collect();
        }

        return Entity::query()->whereKey($user->entity_id)->get(['id', 'code', 'name', 'entity_type']);
    }

    private function periods(array $filters): Collection
    {
        $query = Period::query()
            ->with('fiscalYear')
            ->where('period_code', '>=', self::HISTORICAL_FLOOR)
            ->whereDate('end_date', '<=', now()->toDateString());

        if ($filters['tax_category'] === 'CIT') {
            $query->where('month', 3);
            if ($filters['fiscal_year_id']) {
                $query->where('fiscal_year_id', $filters['fiscal_year_id']);
            }
        } else {
            if ($filters['from_period'] || $filters['to_period']) {
                $query->when($filters['from_period'], fn ($q, $from) => $q->where('period_code', '>=', $from))
                    ->when($filters['to_period'], fn ($q, $to) => $q->where('period_code', '<=', $to));
            } else {
                $latest = (clone $query)->orderByDesc('period_code')->value('period_code');
                if ($latest) {
                    [$year, $month] = array_map('intval', explode('-', $latest));
                    $from = now()->setDate($year, $month, 1)->subMonths(11)->format('Y-m');
                    $query->where('period_code', '>=', max($from, self::HISTORICAL_FLOOR));
                }
            }
        }

        return $query->orderByDesc('period_code')->get();
    }

    private function paginateKeys(Collection $periods, Collection $entities, int $page, int $perPage): LengthAwarePaginator
    {
        $keys = $periods->flatMap(function (Period $period) use ($entities) {
            return $entities->map(fn (Entity $entity) => ['period' => $period, 'entity' => $entity]);
        })->sort(function (array $a, array $b) {
            $periodCompare = strcmp($b['period']->period_code, $a['period']->period_code);
            if ($periodCompare !== 0) {
                return $periodCompare;
            }

            return strcmp($a['entity']->name, $b['entity']->name);
        })->values();

        return new LengthAwarePaginator(
            $keys->forPage($page, $perPage)->values(),
            $keys->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }

    private function caseRow(TaxCase $case, Period $period, Entity $entity, bool $showEntity): array
    {
        return [
            'row_key' => "entity:{$entity->id}|{$case->case_type}|period:{$period->id}",
            'entity' => $this->entityPayload($entity, $showEntity),
            'period' => $this->periodPayload($period),
            'tax_case' => [
                'id' => $case->id,
                'case_number' => $case->case_number,
                'is_completed' => (bool) $case->is_completed,
            ],
            'stages' => $this->stageStatusResolver->resolveAll($case),
            'refund' => $this->refundAggregateResolver->resolve($case),
        ];
    }

    private function missingCaseRow(string $taxCategory, Period $period, Entity $entity, bool $showEntity): array
    {
        $stages = [];
        foreach (StageDefinitions::STAGES as $stageId => $definition) {
            $isSpt = $stageId === 1;
            $stages[$definition['key']] = [
                'stage' => $definition['key'],
                'stage_id' => $stageId,
                'status' => $isSpt ? 'action_required' : 'not_available_yet',
                'label' => $isSpt ? 'Input Required' : 'Not Available',
                'clickable' => $isSpt,
                'action' => $isSpt ? [
                    'type' => 'route',
                    'name' => $taxCategory === 'CIT' ? 'CreateCITCase' : 'CreateVATCase',
                    'params' => [],
                    'query' => [
                        'entity_id' => $entity->id,
                        'period_id' => $period->id,
                        'fiscal_year_id' => $period->fiscal_year_id,
                    ],
                ] : null,
                'reason' => $isSpt ? null : 'Create SPT before continuing this workflow.',
                'source' => [],
                'data_quality' => [],
                'revision' => null,
            ];
        }

        return [
            'row_key' => "entity:{$entity->id}|{$taxCategory}|period:{$period->id}",
            'entity' => $this->entityPayload($entity, $showEntity),
            'period' => $this->periodPayload($period),
            'tax_case' => null,
            'stages' => $stages,
            'refund' => [
                'status' => 'not_available',
                'label' => 'Not Available',
                'count' => 0,
                'clickable' => false,
                'action' => null,
                'reason' => 'Create SPT before refund processing is available.',
                'source' => [],
                'data_quality' => [],
            ],
        ];
    }

    private function filterRows(Collection $rows, array $filters): Collection
    {
        if (! $filters['include_completed']) {
            $rows = $rows->filter(fn ($row) => collect($row['stages'])->contains(fn ($stage) => $stage['status'] !== 'completed'));
        }

        if ($filters['status']) {
            $wanted = collect(explode(',', $filters['status']))->map(fn ($status) => trim($status))->filter()->values();
            $rows = $rows->filter(fn ($row) => collect($row['stages'])->contains(fn ($stage) => $wanted->contains($stage['status'])) || $wanted->contains($row['refund']['status']));
        }

        return $rows;
    }

    private function entityPayload(Entity $entity, bool $showEntity): ?array
    {
        if (! $showEntity) {
            return ['id' => $entity->id, 'code' => $entity->code, 'name' => $entity->name];
        }

        return ['id' => $entity->id, 'code' => $entity->code, 'name' => $entity->name];
    }

    private function periodPayload(Period $period): array
    {
        return [
            'period_id' => $period->id,
            'fiscal_year_id' => $period->fiscal_year_id,
            'fiscal_year' => $period->fiscalYear?->year,
            'period_code' => $period->period_code,
            'label' => $period->start_date?->format('M Y') ?? $period->period_code,
            'sort_key' => $period->period_code,
        ];
    }
}
