<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\NotificationLog;
use App\Models\TaxCase;
use App\Services\KianNotificationService;
use Illuminate\Http\Request;

class NotificationLogController extends Controller
{
    public function index(Request $request) {
        $query=$this->scoped(NotificationLog::query())->with(['entity:id,name','taxCase:id,case_number'])->withCount('attempts')->latest();
        foreach(['entity_id','stage_id','current_status','notification_type'] as $field) if($request->filled($field)) $query->where($field,$request->$field);
        if($request->filled('tax_case')) $query->whereHas('taxCase',fn($q)=>$q->where('case_number','like','%'.$request->tax_case.'%'));
        return response()->json($query->paginate($request->integer('per_page',20)));
    }
    public function show(NotificationLog $notificationLog) {
        abort_unless($this->scoped(NotificationLog::whereKey($notificationLog->id))->exists(),403);
        return response()->json($notificationLog->load(['entity:id,name','taxCase:id,case_number','attempts.initiatedBy:id,name']));
    }
    public function retry(NotificationLog $notificationLog, Request $request, KianNotificationService $service) {
        abort_unless($request->user()->hasRole('Admin'),403,'Only Admin may retry notifications.');
        $attempt=$service->retry($notificationLog,$request->user());
        return response()->json(['message'=>'Notification queued. Recipients will be resolved using current configuration.','attempt'=>$attempt],202);
    }
    public function summary(TaxCase $taxCase) {
        abort_unless($this->canViewEntity($taxCase->entity_id),403);
        return response()->json(NotificationLog::where('tax_case_id',$taxCase->id)->whereIn('stage_id',[4,7,10,12])->with(['attempts'=>fn($q)=>$q->latest('attempt_number')->limit(1)])->latest('id')->get()->keyBy('stage_id'));
    }
    private function scoped($query) { return $this->canViewAll() ? $query : $query->where('entity_id',auth()->user()->entity_id); }
    private function canViewEntity($id): bool { return $this->canViewAll() || (int)auth()->user()->entity_id === (int)$id; }
    private function canViewAll(): bool { $user=auth()->user(); return $user->hasRole('Admin') || $user->entity?->entity_type === 'HOLDING'; }
}
