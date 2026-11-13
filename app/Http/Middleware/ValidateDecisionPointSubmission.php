<?php

namespace App\Http\Middleware;

use App\Models\TaxCase;
use App\Services\DecisionPointTaxExposure;
use App\Services\RevisionMutationGuard;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Keep active stage-specific POSTs inside the same exposure/atomic-write boundary. */
class ValidateDecisionPointSubmission
{
    public function handle(Request $request, Closure $next, string $stage)
    {
        DB::beginTransaction();
        try {
            $case = TaxCase::query()->lockForUpdate()->findOrFail($request->route('taxCase')->id);
            $request->route()->setParameter('taxCase', $case);
            \Illuminate\Support\Facades\Gate::authorize('update', $case);
            abort_unless($request->user()?->is_active && $request->user()->hasPermission('submit_cases'), 403);
            if ((int) $stage === 12) $request->validate(['continue_to_next_stage' => 'prohibited']);
            if ((int) $stage !== 4) app(RevisionMutationGuard::class)->assertStageIsEditable($case, (int) $stage);
            if (in_array((int) $stage, [10, 12], true)) {
                \Illuminate\Support\Facades\Gate::authorize('update', $case);
                $access = app(\App\Services\MainWorkflowTransitionGuard::class)->inspect($case, (int) $stage);
                abort_unless($access['allowed'], $access['status'], $access['message']);
                if (! $case->documents()->where('stage_code', $stage)
                    ->where('document_type', '!=', \App\Models\Document::SPHP_OTHER_FINDINGS)
                    ->whereIn('status', ['DRAFT', 'ACTIVE', 'ARCHIVED'])->exists()) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'supporting_docs' => 'Please upload at least one supporting document before submitting.',
                    ]);
                }
            }
            // Dedicated stores finalize immediately; drafts use /workflow/{stage}.
            if ($request->input('action') === 'draft' || $request->boolean('is_draft')) {
                throw \Illuminate\Validation\ValidationException::withMessages(['action' => 'Save decision drafts through the workflow endpoint.']);
            }
            $request->merge(app(\App\Services\DecisionPointRefundService::class)->normalizeInput($request->all(), false));
            $request->merge(app(DecisionPointTaxExposure::class)->normalizeForCase($case, (int) $stage, $request->all()));
            $response = $next($request);
            if ($response->getStatusCode() >= 400) DB::rollBack();
            else DB::commit();
            return $response;
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
