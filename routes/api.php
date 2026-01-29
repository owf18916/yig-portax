<?php

use App\Models\TaxCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\Api\EntityController;
use App\Http\Controllers\Api\TaxCaseController;
use App\Http\Controllers\Api\TaxCaseExportController;
use App\Http\Controllers\Api\RevisionController;
use App\Http\Controllers\Api\SkpRecordController;
use App\Http\Controllers\Api\Sp2RecordController;
use App\Http\Controllers\Api\FiscalYearController;
use App\Http\Controllers\Api\SphpRecordController;
use App\Http\Controllers\Api\SpuhRecordController;
use App\Http\Controllers\Api\AnnouncementController;
use App\Http\Controllers\Api\RefundProcessController;
use App\Http\Controllers\Api\BankTransferRequestController;
use App\Http\Controllers\Api\PreliminaryRefundRequestController;
use App\Http\Controllers\Api\AppealDecisionController;
use App\Http\Controllers\Api\KianSubmissionController;
use App\Http\Controllers\Api\AppealSubmissionController;
use App\Http\Controllers\Api\ObjectionDecisionController;
use App\Http\Controllers\Api\DashboardAnalyticsController;
use App\Http\Controllers\Api\ObjectionSubmissionController;
use App\Http\Controllers\Api\SupremeCourtDecisionController;
use App\Http\Controllers\Api\SupremeCourtSubmissionController;
use App\Http\Controllers\Api\AppealExplanationRequestController;
use App\Http\Controllers\Api\ExchangeRateController;

// ============================================================================
// AUTHENTICATION ROUTES - Public
// ============================================================================
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth');
Route::get('/me', [AuthController::class, 'me'])->middleware('auth');

// Development: Quick login for testing
if (config('app.debug')) {
    Route::post('/dev-login', function () {
        $user = \App\Models\User::first() ?? \App\Models\User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
            'is_active' => true,
        ]);
        
        \Illuminate\Support\Facades\Auth::login($user);
        return response()->json(['success' => true, 'message' => 'Dev login successful', 'user' => $user]);
    });
}

// ============================================================================
// HEALTH & TEST ENDPOINTS
// ============================================================================
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'message' => 'API is running',
        'timestamp' => now()
    ]);
});

Route::get('/test', function () {
    return response()->json([
        'message' => 'API test endpoint working',
        'data' => [
            'framework' => 'Laravel 12',
            'frontend' => 'Vue.js 3',
            'api_version' => '1.0',
            'workflow_stages' => 12
        ]
    ]);
});

// ============================================================================
// TAX CASE ROUTES - Core case management (require auth)
// ============================================================================
Route::middleware('auth')->prefix('tax-cases')->group(function () {
    Route::get('/', [TaxCaseController::class, 'index'])->name('tax-cases.index');
    Route::post('/', [TaxCaseController::class, 'store'])->name('tax-cases.store');
    Route::get('/export', [TaxCaseExportController::class, 'export'])->name('tax-cases.export');
    
    // ============================================================================
    // NEXT ACTION ROUTES - Update next_action fields for any stage record
    // ============================================================================
    Route::put('{taxCaseId}/next-action/{modelType}/{recordId}', function (Request $request, $taxCaseId, $modelType, $recordId) {
        $modelMap = [
            'tax-cases' => 'App\Models\TaxCase',
            'sp2-records' => 'App\Models\Sp2Record',
            'sphp-records' => 'App\Models\SphpRecord',
            'skp-records' => 'App\Models\SkpRecord',
            'objection-submissions' => 'App\Models\ObjectionSubmission',
            'spuh-records' => 'App\Models\SpuhRecord',
            'objection-decisions' => 'App\Models\ObjectionDecision',
            'appeal-submissions' => 'App\Models\AppealSubmission',
            'appeal-explanation-requests' => 'App\Models\AppealExplanationRequest',
            'appeal-decisions' => 'App\Models\AppealDecision',
            'supreme-court-submissions' => 'App\Models\SupremeCourtSubmission',
            'supreme-court-decisions' => 'App\Models\SupremeCourtDecision',
            'refund-processes' => 'App\Models\RefundProcess',
            'kian-submissions' => 'App\Models\KianSubmission',
        ];
        
        if (!isset($modelMap[$modelType])) {
            return response()->json(['error' => 'Invalid model type: ' . $modelType], 400);
        }
        
        $modelClass = $modelMap[$modelType];
        
        // For tax-cases model type, verify the record ID matches the taxCaseId
        if ($modelType === 'tax-cases' && $recordId != $taxCaseId) {
            return response()->json(['error' => 'Record ID does not match tax case ID'], 400);
        }
        
        $record = $modelClass::find($recordId);
        
        if (!$record) {
            return response()->json(['error' => 'Record not found'], 404);
        }
        
        $validated = $request->validate([
            'next_action' => 'nullable|string|max:1000',
            'next_action_due_date' => 'nullable|date',
            'status_comment' => 'nullable|string|max:1000',
        ]);
        
        $record->update($validated);
        
        return response()->json([
            'success' => true,
            'message' => 'Next action updated successfully',
            'data' => $record
        ]);
    })->name('next-action.update');
    
    Route::prefix('{taxCase}')->group(function () {
        Route::get('/', [TaxCaseController::class, 'show'])->name('tax-cases.show');
        Route::put('/', [TaxCaseController::class, 'update'])->name('tax-cases.update');
        Route::get('/workflow-history', [TaxCaseController::class, 'workflowHistory'])->name('tax-cases.workflow-history');
        Route::get('/documents', [TaxCaseController::class, 'documents'])->name('tax-cases.documents');
        Route::post('/complete', [TaxCaseController::class, 'complete'])->name('tax-cases.complete');
        Route::post('/close', [TaxCaseController::class, 'close'])->name('tax-cases.close');
        
        // Workflow decision routing endpoint - locks workflow path via stage_to
        Route::post('/workflow-decision', function (Request $request, TaxCase $taxCase) {
            $user = auth()->user();
            if (!$user) return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
            
            $validated = $request->validate([
                'current_stage_id' => 'required|integer',
                'next_stage_id' => 'required|integer',
                'decision_type' => 'required|in:objection,refund,appeal,supreme_court',
                'decision_reason' => 'nullable|string',
            ]);
            
            try {
                DB::beginTransaction();
                
                // Validation: Must be from the correct current stage
                $currentStageHistory = $taxCase->workflowHistories()
                    ->where('stage_id', $validated['current_stage_id'])
                    ->where('status', '!=', 'draft')
                    ->latest('created_at')
                    ->first();
                
                if (!$currentStageHistory) {
                    throw new \Exception('Current stage must be submitted before decision routing');
                }
                
                // Update workflow_histories with stage_to to lock the workflow path
                // WHERE tax_case_id = specific case AND stage_id = current_stage
                $taxCase->workflowHistories()
                    ->where('stage_id', $validated['current_stage_id'])
                    ->where('tax_case_id', $taxCase->id)  // IMPORTANT: Specific to this case
                    ->latest('created_at')
                    ->first()
                    ->update([
                        'stage_to' => $validated['next_stage_id'],
                        'decision_point' => 'stage_routing',
                        'decision_value' => $validated['decision_type'],
                    ]);
                
                // Update tax_cases.current_stage to next stage
                $taxCase->update([
                    'current_stage' => $validated['next_stage_id'],
                ]);
                
                // Create new workflow history entry for the next stage (in draft status)
                $taxCase->workflowHistories()->create([
                    'stage_id' => $validated['next_stage_id'],
                    'stage_from' => $validated['current_stage_id'],
                    'stage_to' => null,  // Will be set when user makes a decision at this stage
                    'action' => 'routed',
                    'status' => 'draft',
                    'decision_point' => 'workflow_routing',
                    'decision_value' => $validated['decision_type'],
                    'user_id' => $user->id,
                    'notes' => $validated['decision_reason'] ?? "Routed from Stage {$validated['current_stage_id']} to Stage {$validated['next_stage_id']}",
                ]);
                
                DB::commit();
                
                return response()->json([
                    'success' => true,
                    'message' => "Workflow locked: Stage {$validated['current_stage_id']} → {$validated['decision_type']} path (Stage {$validated['next_stage_id']})",
                    'data' => $taxCase->fresh(['workflowHistories'])
                ]);
                
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('Workflow decision error', [
                    'error' => $e->getMessage(),
                    'case_id' => $taxCase->id,
                    'trace' => $e->getTraceAsString()
                ]);
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage()
                ], 400);
            }
        })->name('tax-cases.workflow-decision');
        
        // ========================================================================
        // REVISION ROUTES - SPT Filling (Stage 1) Revision Management
        // ========================================================================
        Route::prefix('revisions')->group(function () {
            // List all revisions for this tax case
            Route::get('/', [RevisionController::class, 'indexRevisions'])->name('revisions.index');
            
            // Request a new revision
            Route::post('/request', [RevisionController::class, 'requestRevision'])->name('revisions.request');
            
            // Submit revised data (User submits filled-in revised values)
            Route::patch('/{revision}/submit', [RevisionController::class, 'submitRevisedData'])->name('revisions.submit');
            
            // Decide on submitted revision (Holding only)
            Route::patch('/{revision}/decide', [RevisionController::class, 'decideRevision'])->name('revisions.decide');
        });
        
        // Generic workflow endpoint for all stages - save draft or submit
        Route::post('/workflow/{stage}', function (Request $request, TaxCase $taxCase, $stage) {
            // Determine action from request body - must explicitly check for 'draft'
            $action = $request->input('action');
            $isDraft = ($action === 'draft') || ($action === null && $request->boolean('is_draft', false));
            $user = auth()->user();
            
            // Variable to store decision point value for workflow history
            $decisionValue = null;
            
            // DEBUG: Log the request to help troubleshoot
            Log::info('Workflow endpoint called', [
                'stage' => $stage,
                'action' => $action,
                'isDraft' => $isDraft,
                'case_id' => $taxCase->id,
                'request_all' => $request->all()
            ]);
            
            try {
                // Update tax case with form data (only updatable fields)
                $updateData = [];
                
                // Stage 1 (SPT Filing) - tax_cases table fields
                if ($stage === 1) {
                    if ($request->has('period_id')) $updateData['period_id'] = $request->input('period_id');
                    if ($request->has('currency_id')) $updateData['currency_id'] = $request->input('currency_id');
                    if ($request->has('disputed_amount')) $updateData['disputed_amount'] = $request->input('disputed_amount');
                }
                
                // STAGE-SPECIFIC DATA HANDLING
                // Route stage data to appropriate stage record tables
                if ($stage == 2) {
                    $sp2Data = $request->only([
                        'sp2_number', 'issue_date', 'receipt_date',
                        'auditor_name', 'auditor_phone', 'auditor_email', 'notes'
                    ]);
                    $sp2Data['tax_case_id'] = $taxCase->id;
                    \App\Models\Sp2Record::updateOrCreate(
                        ['tax_case_id' => $taxCase->id],
                        $sp2Data
                    );
                    Log::info('SP2Record saved', ['sp2Data' => $sp2Data]);
                } elseif ($stage == 3) {
                    $sphpData = $request->only([
                        'sphp_number', 'sphp_issue_date', 'sphp_receipt_date',
                        'royalty_finding', 'service_finding', 'other_finding', 'other_finding_notes'
                    ]);
                    $sphpData['tax_case_id'] = $taxCase->id;
                    \App\Models\SphpRecord::updateOrCreate(
                        ['tax_case_id' => $taxCase->id],
                        $sphpData
                    );
                    Log::info('SphpRecord saved', ['sphpData' => $sphpData]);
                } elseif ($stage == 4) {
                    // ⭐ DEBUG: Check if decision fields exist in request
                    Log::info('[Stage 4 DEBUG] Request has decision fields?', [
                        'has_create_refund' => $request->has('create_refund'),
                        'has_continue' => $request->has('continue_to_next_stage'),
                        'raw_create_refund' => $request->input('create_refund'),
                        'raw_continue' => $request->input('continue_to_next_stage'),
                    ]);
                    
                    $skpData = $request->only([
                        'skp_number', 'issue_date', 'receipt_date', 'skp_type',
                        'skp_amount', 'royalty_correction', 'service_correction', 'other_correction', 'notes',
                        'correction_notes', 'user_routing_choice', 'create_refund', 'refund_amount', 'continue_to_next_stage'
                    ]);
                    
                    Log::info('[Stage 4] After only() - skpData keys', [
                        'keys' => array_keys($skpData),
                        'full_data' => $skpData
                    ]);
                    
                    $skpData['tax_case_id'] = $taxCase->id;
                    
                    // ⭐ ENSURE BOOLEANS ARE ACTUAL BOOLEANS for decision checkboxes
                    if (isset($skpData['create_refund'])) {
                        $skpData['create_refund'] = filter_var($skpData['create_refund'] ?? false, FILTER_VALIDATE_BOOLEAN);
                    }
                    if (isset($skpData['continue_to_next_stage'])) {
                        $skpData['continue_to_next_stage'] = filter_var($skpData['continue_to_next_stage'] ?? false, FILTER_VALIDATE_BOOLEAN);
                    }
                    
                    Log::info('[Stage 4] Decision Checkboxes', [
                        'create_refund' => $skpData['create_refund'] ?? null,
                        'create_refund_type' => gettype($skpData['create_refund'] ?? null),
                        'continue_to_next_stage' => $skpData['continue_to_next_stage'] ?? null,
                        'continue_to_next_stage_type' => gettype($skpData['continue_to_next_stage'] ?? null),
                    ]);
                    
                    // ⭐ If user_routing_choice is provided, update tax_case next_stage_id
                    if ($request->has('user_routing_choice')) {
                        $routingChoice = $request->input('user_routing_choice');
                        $nextStageId = ($routingChoice === 'refund') ? 13 : 5;
                        $taxCase->update(['next_stage_id' => $nextStageId]);
                        Log::info('Stage 4 Routing Choice', ['choice' => $routingChoice, 'next_stage' => $nextStageId]);
                    }
                    
                    // ⭐ SET decision_value: Store decision checkpoint values as JSON for workflow history
                    $decisionValue = json_encode([
                        'create_refund' => $skpData['create_refund'] ?? false,
                        'refund_amount' => $skpData['refund_amount'] ?? 0,
                        'continue_to_next_stage' => $skpData['continue_to_next_stage'] ?? false,
                        'skp_type' => $request->input('skp_type')
                    ]);
                    
                    Log::info('Stage 4 Decision Value Set', [
                        'decisionValue' => $decisionValue
                    ]);
                    
                    \App\Models\SkpRecord::updateOrCreate(
                        ['tax_case_id' => $taxCase->id],
                        $skpData
                    );
                    Log::info('SkpRecord saved', ['skpData' => $skpData]);

                } elseif ($stage == 5) {
                    $objectionData = $request->only([
                        'objection_number', 'submission_date', 'objection_amount'
                    ]);
                    $objectionData['tax_case_id'] = $taxCase->id;
                    \App\Models\ObjectionSubmission::updateOrCreate(
                        ['tax_case_id' => $taxCase->id],
                        $objectionData
                    );
                    Log::info('ObjectionSubmission saved', ['objectionData' => $objectionData]);
                } elseif ($stage == 6) {
                    $spuhData = $request->only([
                        'spuh_number', 'issue_date', 'receipt_date', 'reply_number', 'reply_date'
                    ]);
                    $spuhData['tax_case_id'] = $taxCase->id;
                    \App\Models\SpuhRecord::updateOrCreate(
                        ['tax_case_id' => $taxCase->id],
                        $spuhData
                    );
                    Log::info('SpuhRecord saved', ['spuhData' => $spuhData]);
                } elseif ($stage == 7) {
                    $decisionData = $request->only([
                        'decision_number', 'decision_date', 'decision_type', 'decision_amount',
                        'create_refund', 'refund_amount', 'continue_to_next_stage'
                    ]);
                    $decisionData['tax_case_id'] = $taxCase->id;
                    
                    // Determine next_stage based on decision_type (auto-routing logic)
                    $decisionType = $request->input('decision_type');
                    $nextStage = null;
                    
                    if ($decisionType === 'granted') {
                        $nextStage = 13;  // Auto-route to Refund
                        Log::info('Decision: GRANTED → Auto-route to Refund (Stage 13)');
                    } elseif ($decisionType === 'rejected') {
                        $nextStage = 8;   // Auto-route to Appeal
                        Log::info('Decision: REJECTED → Auto-route to Appeal (Stage 8)');
                    } elseif ($decisionType === 'partially_granted') {
                        $nextStage = null;  // User must choose - no auto-routing
                        Log::info('Decision: PARTIALLY_GRANTED → User must choose between Appeal or Refund');
                    }
                    
                    // ⭐ Store decision checkpoints in decision_value as JSON
                    $decisionValue = json_encode([
                        'decision_type' => $decisionType,
                        'create_refund' => $request->boolean('create_refund', false),
                        'refund_amount' => $request->input('refund_amount', 0),
                        'continue_to_next_stage' => $request->boolean('continue_to_next_stage', false)
                    ]);
                    
                    $decisionData['next_stage'] = $nextStage;
                    \App\Models\ObjectionDecision::updateOrCreate(
                        ['tax_case_id' => $taxCase->id],
                        $decisionData
                    );
                    Log::info('ObjectionDecision saved', ['decisionData' => $decisionData, 'nextStage' => $nextStage]);
                } elseif ($stage == 8) {
                    $appealData = $request->only([
                        'appeal_number', 'submission_date', 'appeal_amount', 'dispute_number'
                    ]);
                    $appealData['tax_case_id'] = $taxCase->id;
                    \App\Models\AppealSubmission::updateOrCreate(
                        ['tax_case_id' => $taxCase->id],
                        $appealData
                    );
                    Log::info('AppealSubmission saved', ['appealData' => $appealData]);
                } elseif ($stage == 9) {
                    $explanationData = $request->only([
                        'request_number', 'request_issue_date', 'request_receipt_date',
                        'explanation_letter_number', 'explanation_submission_date'
                    ]);
                    $explanationData['tax_case_id'] = $taxCase->id;
                    \App\Models\AppealExplanationRequest::updateOrCreate(
                        ['tax_case_id' => $taxCase->id],
                        $explanationData
                    );
                    Log::info('AppealExplanationRequest saved', ['explanationData' => $explanationData]);
                } elseif ($stage == 10) {
                    $appealDecisionData = $request->only([
                        'decision_number', 'decision_date', 'decision_type',
                        'decision_amount', 'decision_notes',
                        'create_refund', 'refund_amount', 'continue_to_next_stage'
                    ]);
                    $appealDecisionData['tax_case_id'] = $taxCase->id;
                    
                    // Determine next stage based on user_routing_choice (refund=13, supreme_court=11)
                    if ($request->has('user_routing_choice')) {
                        $userChoice = $request->input('user_routing_choice');
                        $appealDecisionData['next_stage'] = ($userChoice === 'refund') ? 13 : 11;
                    }
                    
                    // ⭐ Store decision checkpoints in decision_value as JSON
                    $decisionValue = json_encode([
                        'decision_type' => $request->input('decision_type'),
                        'create_refund' => $request->boolean('create_refund', false),
                        'refund_amount' => $request->input('refund_amount', 0),
                        'continue_to_next_stage' => $request->boolean('continue_to_next_stage', false)
                    ]);
                    
                    \App\Models\AppealDecision::updateOrCreate(
                        ['tax_case_id' => $taxCase->id],
                        $appealDecisionData
                    );
                    Log::info('AppealDecision saved', ['appealDecisionData' => $appealDecisionData]);
                } elseif ($stage == 11) {
                    $supremeCourtSubmissionData = $request->only([
                        'supreme_court_letter_number', 'submission_date', 'review_amount'
                    ]);
                    $supremeCourtSubmissionData['tax_case_id'] = $taxCase->id;
                    
                    // Map supreme_court_letter_number to submission_number for database constraint
                    if (isset($supremeCourtSubmissionData['supreme_court_letter_number'])) {
                        $supremeCourtSubmissionData['submission_number'] = $supremeCourtSubmissionData['supreme_court_letter_number'];
                    }
                    
                    // Map review_amount to submission_amount for database constraint
                    if (isset($supremeCourtSubmissionData['review_amount'])) {
                        $supremeCourtSubmissionData['submission_amount'] = $supremeCourtSubmissionData['review_amount'];
                    }
                    
                    \App\Models\SupremeCourtSubmission::updateOrCreate(
                        ['tax_case_id' => $taxCase->id],
                        $supremeCourtSubmissionData
                    );
                    Log::info('SupremeCourtSubmission saved', ['supremeCourtSubmissionData' => $supremeCourtSubmissionData]);
                } elseif ($stage == 12) {
                    $supremeCourtDecisionData = $request->only([
                        'keputusan_pk_number', 'keputusan_pk_date', 'keputusan_pk',
                        'keputusan_pk_amount', 'keputusan_pk_notes', 'next_action',
                        'create_refund', 'refund_amount', 'continue_to_next_stage'
                    ]);
                    $supremeCourtDecisionData['tax_case_id'] = $taxCase->id;
                    
                    // ⭐ If next_action is provided, update tax_case next_stage_id based on decision
                    if ($request->has('next_action')) {
                        $nextAction = $request->input('next_action');
                        $nextStageId = ($nextAction === 'refund') ? 13 : 16;  // 13=Refund, 16=KIAN
                        $taxCase->update(['next_stage_id' => $nextStageId]);
                        
                        // Also update case_status based on decision
                        $keputusanPk = $request->input('keputusan_pk');
                        $caseStatus = match($keputusanPk) {
                            'dikabulkan' => 'GRANTED',
                            'dikabulkan_sebagian' => 'GRANTED_PARTIAL',
                            'ditolak' => 'NOT_GRANTED_PARTIAL',
                            default => 'SUPREME_COURT_DECISION'
                        };
                        $taxCase->update(['case_status' => $caseStatus]);
                        
                        Log::info('Stage 12 Final Decision', [
                            'keputusan_pk' => $keputusanPk,
                            'next_action' => $nextAction,
                            'case_status' => $caseStatus
                        ]);
                    }
                    
                    // ⭐ Store decision checkpoints in decision_value as JSON
                    $decisionValue = json_encode([
                        'keputusan_pk' => $request->input('keputusan_pk'),
                        'next_action' => $request->input('next_action'),
                        'create_refund' => $request->boolean('create_refund', false),
                        'continue_to_next_stage' => $request->boolean('continue_to_next_stage', false)
                    ]);
                    
                    \App\Models\SupremeCourtDecision::updateOrCreate(
                        ['tax_case_id' => $taxCase->id],
                        $supremeCourtDecisionData
                    );
                    Log::info('SupremeCourtDecision saved', ['supremeCourtDecisionData' => $supremeCourtDecisionData]);
                } elseif ($stage == 13) {
                    // Stage 13: Bank Transfer Request - Initial refund request submission
                    $bankTransferData = $request->only([
                        'request_number', 'transfer_date', 'instruction_number', 'notes'
                    ]);
                    
                    // Get or create refund process for this tax case
                    $refundProcess = \App\Models\RefundProcess::firstOrCreate(
                        ['tax_case_id' => $taxCase->id],
                        [
                            'refund_number' => sprintf('RFD-%s-%05d', $taxCase->fiscal_year ?? date('Y'), 
                                \App\Models\RefundProcess::where('tax_case_id', $taxCase->id)->count() + 1),
                            'refund_amount' => 0,
                            'refund_method' => 'bank_transfer',
                            'refund_status' => 'pending',
                            'submitted_by' => auth()->id(),
                            'submitted_at' => now(),
                            'status' => 'draft',
                        ]
                    );
                    
                    // Create bank transfer request
                    $bankTransferData['refund_process_id'] = $refundProcess->id;
                    $bankTransferData['transfer_number'] = 'TRN-' . $refundProcess->id . '-' . time();
                    $bankTransferData['transfer_status'] = 'pending';
                    $bankTransferData['created_by'] = auth()->id();
                    
                    \App\Models\BankTransferRequest::updateOrCreate(
                        ['refund_process_id' => $refundProcess->id],
                        $bankTransferData
                    );
                    Log::info('BankTransferRequest saved (Stage 13)', ['bankTransferData' => $bankTransferData]);
                } elseif ($stage == 14) {
                    // Stage 14: Surat Instruksi Transfer - Transfer instruction with bank details
                    $transferInstructionData = $request->only([
                        'instruction_number', 'transfer_amount', 'bank_code', 'bank_name',
                        'account_number', 'account_holder', 'notes'
                    ]);
                    
                    // Get the latest refund process
                    $refundProcess = \App\Models\RefundProcess::where('tax_case_id', $taxCase->id)
                        ->latest()
                        ->first();
                    
                    if ($refundProcess) {
                        // Get the latest bank transfer request
                        $bankTransfer = $refundProcess->bankTransferRequests()
                            ->latest()
                            ->first();
                        
                        if ($bankTransfer) {
                            $transferInstructionData['transfer_status'] = 'processing';
                            $bankTransfer->update($transferInstructionData);
                            
                            // Update refund process with the transfer amount
                            $refundProcess->update([
                                'refund_amount' => $transferInstructionData['transfer_amount'],
                                'refund_status' => 'approved',
                                'approved_by' => auth()->id(),
                                'approved_at' => now(),
                                'status' => 'approved',
                            ]);
                            Log::info('TransferInstruction saved (Stage 14)', ['transferInstructionData' => $transferInstructionData]);
                        }
                    }
                } elseif ($stage == 15) {
                    // Stage 15: Refund Received - Confirm receipt of refund
                    $refundReceiptData = $request->only([
                        'receipt_number', 'processed_date', 'receipt_date', 'transfer_amount', 'notes'
                    ]);
                    
                    // Get the latest refund process
                    $refundProcess = \App\Models\RefundProcess::where('tax_case_id', $taxCase->id)
                        ->latest()
                        ->first();
                    
                    if ($refundProcess) {
                        // Get the latest bank transfer request
                        $bankTransfer = $refundProcess->bankTransferRequests()
                            ->latest()
                            ->first();
                        
                        if ($bankTransfer) {
                            $refundReceiptData['transfer_status'] = 'completed';
                            $bankTransfer->update($refundReceiptData);
                            
                            // Mark refund process as completed
                            $refundProcess->update([
                                'refund_status' => 'completed',
                                'refund_amount' => $refundReceiptData['transfer_amount'],
                                'processed_date' => $refundReceiptData['processed_date'],
                            ]);
                            Log::info('RefundReceipt saved (Stage 15)', ['refundReceiptData' => $refundReceiptData]);
                        }
                    }
                }
                
                if ($isDraft) {
                    // For draft, ONLY update the specific fields, DO NOT change case status
                    if (!empty($updateData)) {
                        $taxCase->update($updateData);
                    }
                    
                    // Create workflow history for draft (status='draft')
                    $taxCase->workflowHistories()->create([
                        'stage_id' => $stage,
                        'stage_from' => $taxCase->current_stage,
                        'action' => 'submitted',
                        'status' => 'draft',
                        'user_id' => $user->id,
                        'notes' => 'Stage saved as draft',
                    ]);
                    
                    return response()->json([
                        'success' => true,
                        'message' => "Stage $stage draft saved successfully",
                        'data' => $taxCase
                    ]);
                }
                
                // Submit stage - update case status and create workflow history
                $updateData['case_status_id'] = 2; // SUBMITTED status
                $updateData['current_stage'] = $stage;
                
                $taxCase->update($updateData);
                
                // Create workflow history for submission
                $workflowHistory = $taxCase->workflowHistories()->create([
                    'stage_id' => $stage,
                    'stage_from' => $taxCase->current_stage > $stage ? $taxCase->current_stage : null,
                    'action' => 'submitted',
                    'status' => 'submitted',
                    'user_id' => $user->id,
                    'notes' => "Stage $stage submitted",
                    'decision_value' => $decisionValue, // For Stage 4 (SKP) or Stage 7+ decisions
                ]);
                
                Log::info('Workflow history created', [
                    'stage_id' => $stage,
                    'decision_value' => $decisionValue
                ]);
                
                // STAGE 7 SPECIAL HANDLING: Auto-routing based on decision type
                if ($stage == 7) {
                    $decisionType = $request->input('decision_type');
                    $userChoice = $request->input('user_routing_choice'); // Appeal or Refund
                    $autoRoutedStage = null;
                    
                    if ($decisionType === 'granted') {
                        $autoRoutedStage = 13;
                        $routingReason = 'Automatic routing: Decision GRANTED → Proceed to Refund';
                    } elseif ($decisionType === 'rejected') {
                        $autoRoutedStage = 8;
                        $routingReason = 'Automatic routing: Decision REJECTED → Proceed to Appeal';
                    } elseif ($decisionType === 'partially_granted' && $userChoice) {
                        // Handle user choice for partially_granted
                        $autoRoutedStage = ($userChoice === 'appeal') ? 8 : 13;
                        $routingReason = $userChoice === 'appeal' 
                            ? 'User selected: Partially Granted → Proceed to Appeal'
                            : 'User selected: Partially Granted → Proceed to Refund';
                    }
                    
                    // For auto-routed decisions OR user choices, update workflow history with stage_to
                    if ($autoRoutedStage) {
                        $workflowHistory->update([
                            'stage_to' => $autoRoutedStage,
                            'decision_point' => 'objection_decision',
                            'decision_value' => $decisionType,
                            'notes' => $routingReason
                        ]);
                        
                        // Create next stage entry in draft status
                        $taxCase->workflowHistories()->create([
                            'stage_id' => $autoRoutedStage,
                            'stage_from' => 7,
                            'action' => 'routed',
                            'status' => 'draft',
                            'user_id' => $user->id,
                            'notes' => "Auto-created from Stage 7 decision: $decisionType",
                        ]);
                        
                        Log::info("Stage 7 Routing triggered", [
                            'case_id' => $taxCase->id,
                            'decision_type' => $decisionType,
                            'user_choice' => $userChoice,
                            'routed_to_stage' => $autoRoutedStage
                        ]);
                    } else {
                        // For partially_granted without user choice, user must choose - no auto-routing yet
                        Log::info("Stage 7 Partially Granted - User choice required", [
                            'case_id' => $taxCase->id
                        ]);
                    }
                }
                
                return response()->json([
                    'success' => true,
                    'message' => "Stage $stage submitted successfully",
                    'data' => $taxCase->fresh([
                        'workflowHistories',
                        'skpRecord',           // ⭐ For Stage 4 routing choice
                        'objectionDecision',   // ⭐ For Stage 7 decision_type (CRITICAL!)
                        'appealDecision',      // ⭐ For Stage 10 decision_type
                        'supremeCourtDecision' // ⭐ For Stage 12 decision_type
                    ])
                ]);
            } catch (\Exception $e) {
                Log::error('Workflow endpoint error', [
                    'stage' => $stage,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Error processing workflow: ' . $e->getMessage()
                ], 500);
            }
        })->name('tax-cases.workflow');
        
        // SPHP Records - Stage 3
        Route::post('/sphp-records', [SphpRecordController::class, 'store'])->name('sphp-records.store');
        Route::get('/sphp-records', [SphpRecordController::class, 'show'])->name('sphp-records.show');
        Route::post('/sphp-records/{sphpRecord}/approve', [SphpRecordController::class, 'approve'])->name('sphp-records.approve');
        Route::post('/sphp-records/{sphpRecord}/reject', [SphpRecordController::class, 'reject'])->name('sphp-records.reject');
        
        // SP2 Records - Stage 2
        Route::post('/sp2-records', [Sp2RecordController::class, 'store'])->name('sp2-records.store');
        Route::get('/sp2-records', [Sp2RecordController::class, 'show'])->name('sp2-records.show');
        Route::post('/sp2-records/{sp2Record}/approve', [Sp2RecordController::class, 'approve'])->name('sp2-records.approve');
        Route::post('/sp2-records/{sp2Record}/reject', [Sp2RecordController::class, 'reject'])->name('sp2-records.reject');
        
        // SKP Records - Stage 4
        Route::post('/skp-records', [SkpRecordController::class, 'store'])->name('skp-records.store');
        Route::get('/skp-records', [SkpRecordController::class, 'show'])->name('skp-records.show');
        Route::post('/skp-records/{skpRecord}/approve', [SkpRecordController::class, 'approve'])->name('skp-records.approve');
        
        // Objection Submissions - Stage 5
        Route::post('/objection-submissions', [ObjectionSubmissionController::class, 'store'])->name('objection-submissions.store');
        Route::get('/objection-submissions', [ObjectionSubmissionController::class, 'show'])->name('objection-submissions.show');
        Route::post('/objection-submissions/{objectionSubmission}/submit', [ObjectionSubmissionController::class, 'submit'])->name('objection-submissions.submit');
        Route::post('/objection-submissions/{objectionSubmission}/withdraw', [ObjectionSubmissionController::class, 'withdraw'])->name('objection-submissions.withdraw');
        
        // SPUH Records - Stage 6
        Route::post('/spuh-records', [SpuhRecordController::class, 'store'])->name('spuh-records.store');
        Route::get('/spuh-records', [SpuhRecordController::class, 'show'])->name('spuh-records.show');
        
        // Objection Decisions - Stage 7
        Route::post('/objection-decisions', [ObjectionDecisionController::class, 'store'])->name('objection-decisions.store');
        Route::get('/objection-decisions', [ObjectionDecisionController::class, 'show'])->name('objection-decisions.show');
        Route::post('/objection-decisions/{decision}/approve', [ObjectionDecisionController::class, 'approve'])->name('objection-decisions.approve');
        
        // Appeal Submissions - Stage 8
        Route::post('/appeal-submissions', [AppealSubmissionController::class, 'store'])->name('appeal-submissions.store');
        Route::get('/appeal-submissions', [AppealSubmissionController::class, 'show'])->name('appeal-submissions.show');
        Route::post('/appeal-submissions/{appealSubmission}/submit', [AppealSubmissionController::class, 'submit'])->name('appeal-submissions.submit');
        Route::post('/appeal-submissions/{appealSubmission}/withdraw', [AppealSubmissionController::class, 'withdraw'])->name('appeal-submissions.withdraw');
        
        // Appeal Explanation Requests - Stage 9
        Route::post('/appeal-explanation-requests', [AppealExplanationRequestController::class, 'store'])->name('appeal-explanations.store');
        Route::get('/appeal-explanation-requests', [AppealExplanationRequestController::class, 'show'])->name('appeal-explanations.show');
        Route::post('/appeal-explanation-requests/{explanationRequest}/submit', [AppealExplanationRequestController::class, 'submit'])->name('appeal-explanations.submit');
        
        // Appeal Decisions - Stage 10
        Route::post('/appeal-decisions', [AppealDecisionController::class, 'store'])->name('appeal-decisions.store');
        Route::get('/appeal-decisions', [AppealDecisionController::class, 'show'])->name('appeal-decisions.show');
        Route::post('/appeal-decisions/{decision}/approve', [AppealDecisionController::class, 'approve'])->name('appeal-decisions.approve');
        
        // Supreme Court Submissions - Stage 11
        Route::post('/supreme-court-submissions', [SupremeCourtSubmissionController::class, 'store'])->name('supreme-court-submissions.store');
        Route::get('/supreme-court-submissions', [SupremeCourtSubmissionController::class, 'show'])->name('supreme-court-submissions.show');
        Route::post('/supreme-court-submissions/{supremeCourtSubmission}/submit', [SupremeCourtSubmissionController::class, 'submit'])->name('supreme-court-submissions.submit');
        Route::post('/supreme-court-submissions/{supremeCourtSubmission}/withdraw', [SupremeCourtSubmissionController::class, 'withdraw'])->name('supreme-court-submissions.withdraw');
        
        // Supreme Court Decisions - Stage 11b
        Route::post('/supreme-court-decisions', [SupremeCourtDecisionController::class, 'store'])->name('supreme-court-decisions.store');
        Route::get('/supreme-court-decisions', [SupremeCourtDecisionController::class, 'show'])->name('supreme-court-decisions.show');
        Route::post('/supreme-court-decisions/{supremeCourtDecision}/approve', [SupremeCourtDecisionController::class, 'approve'])->name('supreme-court-decisions.approve');
        
        // Refund Stages - Workflow stages 13, 14, 15
        // GET stage data
        Route::get('/workflow/13', [BankTransferRequestController::class, 'show'])->name('workflow.stage-13.show');
        Route::get('/workflow/14', [BankTransferRequestController::class, 'showTransferInstruction'])->name('workflow.stage-14.show');
        Route::get('/workflow/15', [BankTransferRequestController::class, 'showRefundReceipt'])->name('workflow.stage-15.show');
        
        // POST stage data
        Route::post('/workflow/13', [BankTransferRequestController::class, 'createTransferRequest'])->name('workflow.stage-13.store');
        Route::post('/workflow/14', [BankTransferRequestController::class, 'updateTransferInstruction'])->name('workflow.stage-14.store');
        Route::post('/workflow/15', [BankTransferRequestController::class, 'completeRefund'])->name('workflow.stage-15.store');
        
        Route::get('/refund-processes', [RefundProcessController::class, 'index'])->name('refund-processes.index');
        Route::post('/refund-processes', [RefundProcessController::class, 'store'])->name('refund-processes.store');
        Route::get('/refund-processes/{refundProcess}', [RefundProcessController::class, 'show'])->name('refund-processes.show');
        Route::post('/refund-processes/{refundProcess}/approve', [RefundProcessController::class, 'approve'])->name('refund-processes.approve');
        Route::post('/refund-processes/{refundProcess}/bank-transfers', [RefundProcessController::class, 'addBankTransfer'])->name('bank-transfers.store');
        Route::get('/refund-processes/{refundProcess}/bank-transfers', [RefundProcessController::class, 'bankTransfers'])->name('bank-transfers.index');
        Route::post('/refund-processes/{refundProcess}/bank-transfers/{transfer}/process', [RefundProcessController::class, 'processBankTransfer'])->name('bank-transfers.process');
        Route::post('/refund-processes/{refundProcess}/bank-transfers/{transfer}/reject', [RefundProcessController::class, 'rejectBankTransfer'])->name('bank-transfers.reject');
        
        // Preliminary Refund Requests - For Pengembalian Pendahuluan cases
        Route::get('/preliminary-refund-request', [PreliminaryRefundRequestController::class, 'show'])->name('preliminary-refund-requests.show');
        Route::post('/preliminary-refund-request', [PreliminaryRefundRequestController::class, 'store'])->name('preliminary-refund-requests.store');
        Route::put('/preliminary-refund-request', [PreliminaryRefundRequestController::class, 'update'])->name('preliminary-refund-requests.update');
        Route::delete('/preliminary-refund-request', [PreliminaryRefundRequestController::class, 'destroy'])->name('preliminary-refund-requests.destroy');
        Route::post('/preliminary-refund-request/approve', [PreliminaryRefundRequestController::class, 'approve'])->name('preliminary-refund-requests.approve');
        Route::post('/preliminary-refund-request/reject', [PreliminaryRefundRequestController::class, 'reject'])->name('preliminary-refund-requests.reject');
        
        // KIAN Submissions - Stage 12
        Route::post('/kian-submissions', [KianSubmissionController::class, 'store'])->name('kian-submissions.store');
        Route::get('/kian-submissions', [KianSubmissionController::class, 'show'])->name('kian-submissions.show');
        Route::post('/kian-submissions/{kianSubmission}/submit', [KianSubmissionController::class, 'submit'])->name('kian-submissions.submit');
        Route::post('/kian-submissions/{kianSubmission}/record-response', [KianSubmissionController::class, 'recordResponse'])->name('kian-submissions.record-response');
        Route::post('/kian-submissions/{kianSubmission}/close', [KianSubmissionController::class, 'close'])->name('kian-submissions.close');
    });
});

// ============================================================================
// REFERENCE DATA ROUTES - Master data for forms (require auth)
// ============================================================================
Route::middleware('auth')->group(function () {
    Route::get('/entities', [EntityController::class, 'index']);
    Route::get('/fiscal-years', [FiscalYearController::class, 'index']);
    
    Route::get('/periods', function () {
        return response()->json(\App\Models\Period::where('is_closed', false)->orderBy('year')->orderBy('month')->get());
    });

    Route::get('/currencies', function () {
        return response()->json(\App\Models\Currency::where('is_active', true)->get());
    });
    Route::get('/case-statuses', function () {
        return response()->json(\App\Models\CaseStatus::where('is_active', true)->orderBy('sort_order')->get());
    });

    Route::get('/user', function (Request $request) {
        $user = $request->user()->load(['role', 'entity']);
        return response()->json($user);
    });

    // ============================================================================
    // ANNOUNCEMENTS ROUTES
    // ============================================================================
    Route::apiResource('announcements', AnnouncementController::class);

    // ============================================================================
    // EXCHANGE RATE ROUTES
    // ============================================================================
    Route::prefix('exchange-rates')->group(function () {
        Route::get('/', [ExchangeRateController::class, 'index'])->name('exchange-rates.index');
        Route::put('/', [ExchangeRateController::class, 'update'])->name('exchange-rates.update');
        Route::get('/{currency}', [ExchangeRateController::class, 'show'])->name('exchange-rates.show');
    });

    // ============================================================================
    // DASHBOARD ANALYTICS ROUTES
    // ============================================================================
    Route::prefix('dashboard')->group(function () {
        Route::get('/charts', [DashboardAnalyticsController::class, 'dashboardCharts']);
        Route::get('/open-cases', [DashboardAnalyticsController::class, 'openCasesPerEntity']);
        Route::get('/disputed-amounts', [DashboardAnalyticsController::class, 'disputedAmountPerEntity']);
    });

    // ============================================================================
    // DOCUMENT UPLOAD & DOWNLOAD ROUTES
    // ============================================================================
    Route::prefix('documents')->group(function () {
        Route::get('/', [DocumentController::class, 'index'])->name('documents.index');
        Route::post('/', [DocumentController::class, 'store'])->name('documents.store');
        Route::get('/{document}/view', [DocumentController::class, 'view'])->name('documents.view');
        Route::get('/{document}/download', [DocumentController::class, 'download'])->name('documents.download');
        Route::delete('/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');
    });
});