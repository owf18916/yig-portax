<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\TaxCase;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class MainStageFollowUp
{
    public function update(Request $request, TaxCase $boundCase, string $modelClass, int $recordId, int $stage): JsonResponse
    {
        return DB::transaction(function () use ($request, $boundCase, $modelClass, $recordId, $stage) {
            // Every close/complete writer takes this parent lock before operational writes.
            $case = TaxCase::query()->lockForUpdate()->findOrFail($boundCase->id);
            Gate::authorize('update', $case);
            $record = $modelClass === TaxCase::class ? $case : $modelClass::query()->lockForUpdate()->findOrFail($recordId);
            abort_unless((int) $record->id === $recordId, 404);
            abort_unless((int) ($record instanceof TaxCase ? $record->id : $record->tax_case_id) === (int) $case->id, 404);
            $closure = app(TaxCaseClosure::class);
            $state = app(MainWorkflowStateResolver::class)->resolve($case);
            $open = $stage === 12 ? $closure->canUpdateStageTwelve($case, $state)
                : (! $case->is_completed && $closure->classification($case) === 'open');
            abort_unless($open, 409, 'Follow-up is read-only for closed or unresolved legacy cases.');
            $access = app(MainWorkflowTransitionGuard::class)->inspect($case, $stage, true);
            abort_unless($access['allowed'], $access['status'], $access['message']);
            $actionKey = $stage === 12 ? 'follow_up_next_action' : 'next_action';
            $rules = [$actionKey => 'sometimes|nullable|string|max:1000',
                'next_action_due_date' => $stage === 12 ? 'sometimes|nullable|date_format:Y-m-d' : 'sometimes|nullable|date',
                'status_comment' => 'sometimes|nullable|string|max:1000'];
            if (array_diff(array_keys($request->all()), array_keys($rules))) {
                throw ValidationException::withMessages(['follow_up' => 'Only operational Next Action, Next Due Date and Status Comment may be updated.']);
            }
            $values = $request->validate($rules);
            $before = $record->getRawOriginal();
            $record->fill($values);
            $changes = array_intersect_key($record->getDirty(), $values);
            if ($changes) {
                $record->save();
                AuditLog::create([
                    'auditable_type' => $record->getMorphClass(), 'auditable_id' => $record->id,
                    'user_id' => $request->user()->id, 'action' => 'updated', 'model_name' => class_basename($record),
                    'old_values' => array_intersect_key($before, $changes), 'new_values' => $changes,
                    'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(), 'performed_at' => now(),
                ]);
            }

            return response()->json(['success' => true, 'message' => 'Next action updated successfully', 'data' => $record]);
        });
    }
}
