<?php

namespace App\Services;

use App\Models\TaxCase;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/** The Tax Case List and export share eligibility, before pagination/history expansion. */
class TaxCaseListFilters
{
    // Preserve the list's display rule, including old branch pointers: highest main
    // history stage, regardless of status. Independent processes are not main stages.
    public const DISPLAY_STAGE = '(CASE WHEN tax_cases.current_stage BETWEEN 1 AND 12 THEN tax_cases.current_stage ELSE (SELECT MAX(stage_id) FROM workflow_histories WHERE tax_case_id = tax_cases.id AND stage_id BETWEEN 1 AND 12) END)';

    // Latest evidence for the raw current stage, as in TaxCase::stage_status.
    // ID makes equal timestamps deterministic; missing/null/empty status is draft.
    public const STAGE_STATUS = "COALESCE(NULLIF((SELECT status FROM workflow_histories WHERE tax_case_id = tax_cases.id AND stage_id = tax_cases.current_stage ORDER BY created_at DESC, id DESC LIMIT 1), ''), 'draft')";

    public function validate(Request $request): array
    {
        return $request->validate([
            'search' => 'nullable|string|max:255',
            'case_number' => 'nullable|string|max:255',
            'case_type' => 'nullable|in:CIT,VAT',
            'entity_id' => 'nullable|integer|min:1',
            'status_id' => 'nullable|integer|min:1',
            'case_status' => 'nullable|in:open,closed',
            'current_stage' => 'nullable|integer|between:1,12',
            'stage_status' => 'nullable|in:draft,submitted,approved,rejected,completed',
            'fiscal_year' => 'nullable|integer|between:1,9999',
            'period_code' => 'nullable|string|max:255|prohibits:period_id',
            // Numeric relationship ID remains supported for existing API callers.
            'period_id' => 'nullable|integer|min:1|prohibits:period_code',
        ]);
    }

    public function authorized(?User $user): Builder
    {
        $query = TaxCase::query();
        if (! $user?->entity) {
            $query->whereRaw('1 = 0');
        } elseif ($user->entity->entity_type !== 'HOLDING') {
            $query->where('tax_cases.entity_id', $user->entity_id);
        }

        return $query;
    }

    public function apply(Builder $query, array $filters): Builder
    {
        $filters = array_filter($filters, fn ($value) => $value !== null && $value !== '');
        foreach (['entity_id', 'case_type', 'period_id'] as $key) {
            if (isset($filters[$key])) {
                $query->where('tax_cases.'.$key, $filters[$key]);
            }
        }
        if (isset($filters['status_id'])) {
            $query->where('case_status_id', $filters['status_id']);
        }
        if (isset($filters['case_status'])) {
            $query->where('is_completed', $filters['case_status'] === 'closed');
        }
        if (isset($filters['current_stage'])) {
            $query->whereRaw(self::DISPLAY_STAGE.' = ?', [(int) $filters['current_stage']]);
        }
        if (isset($filters['stage_status'])) {
            $query->whereRaw(self::STAGE_STATUS.' = ?', [$filters['stage_status']]);
        }
        if (isset($filters['period_code'])) {
            $query->whereHas('period', fn ($q) => $q->where('period_code', $filters['period_code']));
        }
        if (isset($filters['fiscal_year'])) {
            $query->whereHas('fiscalYear', fn ($q) => $q->where('year', $filters['fiscal_year']));
        }
        if (isset($filters['case_number'])) {
            $this->contains($query, 'case_number', $filters['case_number']);
        }
        if (isset($filters['search'])) {
            $query->where(function ($q) use ($filters) {
                $this->contains($q, 'case_number', $filters['search']);
                $q->orWhereHas('entity', fn ($entity) => $this->contains($entity, 'name', $filters['search']));
            });
        }

        return $query;
    }

    private function contains(Builder $query, string $column, string $value): void
    {
        // Literal, case-insensitive substring, matching the UI (%, _ are not wildcards).
        $value = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($value));
        $query->whereRaw("LOWER({$column}) LIKE ? ESCAPE '!'", ['%'.$value.'%']);
    }
}
