<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\NotificationLog;
use App\Models\TaxCase;
use App\Services\KianNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class NotificationLogController extends Controller
{
    public function index(Request $request) {
        $query=$this->scoped(NotificationLog::query())->with(['entity:id,name','taxCase:id,case_number'])->withCount('attempts')->latest();
        foreach(['entity_id','stage_id','current_status','notification_type'] as $field) if($request->filled($field)) $query->where($field,$request->$field);
        if($request->filled('tax_case')) $query->whereHas('taxCase',fn($q)=>$q->where('case_number','like','%'.$request->tax_case.'%'));
        return response()->json($query->paginate($request->integer('per_page',20)));
    }
    public function show(NotificationLog $notificationLog) {
        $this->authorizeTaxCase($notificationLog->taxCase);
        return response()->json($notificationLog->load(['entity:id,name','taxCase:id,case_number','attempts.initiatedBy:id,name']));
    }
    public function retry(NotificationLog $notificationLog, Request $request, KianNotificationService $service) {
        $this->authorizeTaxCase($notificationLog->taxCase);
        abort_unless($request->user()->hasRole('Admin'),403,'Only Admin may retry notifications.');
        $attempt=$service->retry($notificationLog,$request->user());
        return response()->json(['message'=>'Notification queued. Recipients will be resolved using current configuration.','attempt'=>$attempt],202);
    }
    public function summary(TaxCase $taxCase) {
        Gate::authorize('view', $taxCase);
        return response()->json(NotificationLog::where('tax_case_id',$taxCase->id)->whereIn('stage_id',[4,7,10,12])->with(['attempts'=>fn($q)=>$q->latest('attempt_number')->limit(1)])->latest('id')->get()->keyBy('stage_id'));
    }
    private function scoped($query) {
        $user = auth()->user();
        if (!$user->entity) return $query->whereRaw('1 = 0');
        return $user->entity->entity_type === 'HOLDING'
            ? $query
            : $query->whereHas('taxCase', fn($cases) => $cases->where('entity_id', $user->entity_id));
    }
    private function authorizeTaxCase(?TaxCase $taxCase): void {
        abort_if($taxCase === null, 403, 'Notification has no valid tax case ownership.');
        Gate::authorize('view', $taxCase);
    }
}
