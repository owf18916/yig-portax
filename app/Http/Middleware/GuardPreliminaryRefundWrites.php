<?php

namespace App\Http\Middleware;

use App\Models\RefundProcess;
use App\Models\TaxCase;
use App\Services\PreliminaryRefundLifecycle;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class GuardPreliminaryRefundWrites
{
    public function handle(Request $request, Closure $next): mixed
    {
        if ($request->isMethodSafe()) {
            return $next($request);
        }
        $case = $request->route('taxCase');
        if (! $case) {
            $case = TaxCase::findOrFail($request->route('document')?->tax_case_id ?? $request->integer('tax_case_id'));
        }
        $hasEvidence = RefundProcess::withTrashed()->where('tax_case_id', $case->id)
            ->where(fn ($q) => $q->where('stage_id', 0)->orWhere('stage_source', 'PRELIMINARY'))->exists();
        $administrative = str_ends_with((string) $request->route()->getControllerClass(), 'PreliminaryRefundRequestController');
        $preliminary = $administrative || $case->spt_type === 'Pengembalian Pendahuluan' || $hasEvidence
            || ($request->has('stage_id') && is_numeric($request->input('stage_id')) && (float) $request->input('stage_id') == 0)
            || $request->input('stage_source') === 'PRELIMINARY';
        if (! $preliminary) {
            return $next($request);
        }

        if ($administrative) {
            Gate::authorize('recordPreliminaryDecision', $case);
            $child = $request->route('preliminaryRequest');
            if ($child) {
                abort_unless((int) $child->tax_case_id === $case->id, 404);
            }

            // The lifecycle owns its commit and file compensation boundary.
            return $next($request);
        }

        return DB::transaction(function () use ($request, $next, $case) {
            $locked = TaxCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
            Gate::authorize('update', $locked);
            $owner = app(PreliminaryRefundLifecycle::class);
            $action = $request->route()->getActionMethod();
            $controller = $request->route()->getControllerClass();
            $payment = str_ends_with((string) $controller, 'RefundStageController')
                && in_array($action, ['createRefundStage2', 'updateRefundStage3', 'completeRefundStage4'], true);
            if ($payment) {
                $owner->check((bool) $request->route('refundId'), 'Select the preliminary Refund explicitly.');

                return $next($request);
            }
            $owner->open($locked);
            if (($locked->case_type === 'VAT' && $locked->spt_position_type === 'LB' && $locked->spt_type === 'Pengembalian Pendahuluan') || $locked->refundProcesses()->where(fn ($q) => $q->where('stage_id', 0)->orWhere('stage_source', 'PRELIMINARY'))->exists()) {
                $stage = (int) $request->route('stage');
                $owner->check(! ($stage >= 2 && $stage <= 12) && $action !== 'storeWorkflowHistory', 'Use the administrative preliminary route; a later audit requires a separate case.');
                $owner->check(! preg_match('/(Sp2|Sphp|Skp|Spuh|Objection|Appeal|SupremeCourt)/', (string) $controller), 'A later audit requires a separate case.');
            }
            $refundWriter = str_contains((string) $controller, 'Refund') || str_contains((string) $controller, 'BankTransfer');
            $owner->check(! $refundWriter && ! in_array($action, ['close', 'complete'], true), 'Use the recorded Tax Office decision and scoped Refund stages 2–4 for preliminary payment.');

            return $next($request);
        });
    }
}
