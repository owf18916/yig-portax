<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NotificationLog;
use App\Models\TaxCase;
use App\Models\User;
use App\Services\KianNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class NotificationLogController extends Controller
{
    private function actor(): User
    {
        $actor = User::with(['role', 'entity'])->find(auth()->id());
        abort_unless(app(KianNotificationService::class)->activeActor($actor), 403);

        return $actor;
    }

    public function index(Request $request)
    {
        $actor = $this->actor();
        $query = NotificationLog::whereHas('taxCase')->with(['entity:id,name', 'taxCase:id,case_number,entity_id', 'latestAttempt'])->withCount('attempts')->latest('id');
        if (! $actor->entity) {
            $query->whereRaw('1 = 0');
        } elseif ($actor->entity->entity_type !== 'HOLDING') {
            $query->whereHas('taxCase', fn ($q) => $q->where('entity_id', $actor->entity_id));
        }
        foreach (['entity_id', 'stage_id', 'current_status', 'notification_type'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->$field);
            }
        }
        if ($request->filled('tax_case')) {
            $query->whereHas('taxCase', fn ($q) => $q->where('case_number', 'like', '%'.mb_substr($request->string('tax_case'), 0, 100).'%'));
        }
        $page = $query->paginate(max(1, min(100, $request->integer('per_page', 20))));
        $page->getCollection()->transform(function ($log) use ($actor) {
            $data = $log->toArray();
            unset($data['latest_attempt']);
            $data['can_retry'] = app(KianNotificationService::class)->canRetry($log, $actor);

            return $data;
        });

        return response()->json($page);
    }

    public function show(NotificationLog $notificationLog)
    {
        $actor = $this->actor();
        abort_if($notificationLog->taxCase === null, 403, 'Notification has no valid case ownership.');
        Gate::forUser($actor)->authorize('view', $notificationLog->taxCase);
        $notificationLog->load(['entity:id,name', 'taxCase:id,case_number,entity_id', 'latestAttempt']);
        $data = $notificationLog->toArray();
        unset($data['latest_attempt']);
        $data['can_retry'] = app(KianNotificationService::class)->canRetry($notificationLog, $actor);
        $data['attempts'] = $notificationLog->attempts()->latest('attempt_number')->limit(50)->with('initiatedBy:id,name')->get();
        $data['attempts_limit'] = 50;

        return response()->json($data);
    }

    public function retry(NotificationLog $notificationLog, Request $request, KianNotificationService $service)
    {
        $actor = $this->actor();
        abort_if($notificationLog->taxCase === null, 403, 'Notification has no valid case ownership.');
        Gate::forUser($actor)->authorize('view', $notificationLog->taxCase);
        abort_unless($actor->hasPermission('retry_notifications'), 403);
        if ($request->has('tax_case_id')) {
            abort_unless((string) $request->input('tax_case_id') === (string) $notificationLog->tax_case_id, 404);
        }
        $attempt = $service->retry($notificationLog, $actor);

        return response()->json(['message' => 'Notification queued. Current eligible recipients will be resolved before transport.', 'attempt' => $attempt], 202);
    }

    public function summary(TaxCase $taxCase)
    {
        $actor = $this->actor();
        Gate::forUser($actor)->authorize('view', $taxCase);

        return response()->json(app(KianNotificationService::class)->summary($taxCase, $actor));
    }
}
