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
            if ((int) $stage !== 4) app(RevisionMutationGuard::class)->assertStageIsEditable($case, (int) $stage);
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
