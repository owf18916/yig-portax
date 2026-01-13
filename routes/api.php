<?php

use App\Models\TaxCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EntityController;
use App\Http\Controllers\Api\TaxCaseController;
use App\Http\Controllers\Api\SkpRecordController;
use App\Http\Controllers\Api\Sp2RecordController;
use App\Http\Controllers\Api\FiscalYearController;
use App\Http\Controllers\Api\SphpRecordController;
use App\Http\Controllers\Api\SpuhRecordController;
use App\Http\Controllers\Api\AnnouncementController;
use App\Http\Controllers\Api\RefundProcessController;
use App\Http\Controllers\Api\AppealDecisionController;
use App\Http\Controllers\Api\KianSubmissionController;
use App\Http\Controllers\Api\AppealSubmissionController;
use App\Http\Controllers\Api\ObjectionDecisionController;
use App\Http\Controllers\Api\DashboardAnalyticsController;
use App\Http\Controllers\Api\ObjectionSubmissionController;
use App\Http\Controllers\Api\RevisionController;
use App\Http\Controllers\Api\SupremeCourtDecisionController;
use App\Http\Controllers\Api\SupremeCourtSubmissionController;
use App\Http\Controllers\Api\AppealExplanationRequestController;
use App\Http\Controllers\DocumentController;

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
    
    Route::prefix('{taxCase}')->group(function () {
        Route::get('/', [TaxCaseController::class, 'show'])->name('tax-cases.show');
        Route::put('/', [TaxCaseController::class, 'update'])->name('tax-cases.update');
        Route::get('/workflow-history', [TaxCaseController::class, 'workflowHistory'])->name('tax-cases.workflow-history');
        Route::get('/documents', [TaxCaseController::class, 'documents'])->name('tax-cases.documents');
        Route::post('/complete', [TaxCaseController::class, 'complete'])->name('tax-cases.complete');
        
        // ========================================================================
        // REVISION ROUTES - SPT Filling (Stage 1) Revision Management
        // ========================================================================
        Route::prefix('revisions')->group(function () {
            // List all revisions for this tax case
            Route::get('/', [RevisionController::class, 'listRevisions'])->name('revisions.list');
            
            // Request a new revision
            Route::post('/request', [RevisionController::class, 'requestRevision'])->name('revisions.request');
            
            // Approve or reject revision request (Holding only)
            Route::patch('/{revision}/approve', [RevisionController::class, 'approveRevision'])->name('revisions.approve');
            
            // Submit revised data (User/PIC only, after approval)
            Route::patch('/{revision}/submit', [RevisionController::class, 'submitRevision'])->name('revisions.submit');
            
            // Decide on submitted revision (Holding only)
            Route::patch('/{revision}/decide', [RevisionController::class, 'decideRevision'])->name('revisions.decide');
            
            // Get revision detail with before-after comparison
            Route::get('/{revision}', [RevisionController::class, 'showRevision'])->name('revisions.show');
        });
        
        // Generic workflow endpoint for all stages - save draft or submit
        Route::post('/workflow/{stage}', function (Request $request, TaxCase $taxCase, $stage) {
            $isDraft = $request->has('draft') && $request->get('draft') === 'true';
            $user = auth()->user();
            
            // Update tax case with form data (only updatable fields)
            $updateData = [];
            if ($request->has('period_id')) $updateData['period_id'] = $request->input('period_id');
            if ($request->has('currency_id')) $updateData['currency_id'] = $request->input('currency_id');
            if ($request->has('disputed_amount')) $updateData['disputed_amount'] = $request->input('disputed_amount');
            
            if ($isDraft) {
                // For draft, just update the data
                if (!empty($updateData)) {
                    $taxCase->update($updateData);
                }
                
                return response()->json([
                    'success' => true,
                    'message' => "Stage $stage draft saved successfully",
                    'data' => $taxCase
                ]);
            }
            
            // Submit stage - update case status and tracking fields
            $updateData['case_status_id'] = 2; // SUBMITTED status
            $updateData['submitted_by'] = $user->id;
            $updateData['submitted_at'] = now();
            $updateData['last_updated_by'] = $user->id;
            $updateData['current_stage'] = $stage;
            
            $taxCase->update($updateData);
            
            return response()->json([
                'success' => true,
                'message' => "Stage $stage submitted successfully",
                'data' => $taxCase
            ]);
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
        Route::post('/spuh-records/{spuhRecord}/approve', [SpuhRecordController::class, 'approve'])->name('spuh-records.approve');
        
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
        
        // Refund Processes - Stage 12
        Route::post('/refund-processes', [RefundProcessController::class, 'store'])->name('refund-processes.store');
        Route::get('/refund-processes', [RefundProcessController::class, 'show'])->name('refund-processes.show');
        Route::post('/refund-processes/{refundProcess}/approve', [RefundProcessController::class, 'approve'])->name('refund-processes.approve');
        Route::post('/refund-processes/{refundProcess}/bank-transfers', [RefundProcessController::class, 'addBankTransfer'])->name('bank-transfers.store');
        Route::get('/refund-processes/{refundProcess}/bank-transfers', [RefundProcessController::class, 'bankTransfers'])->name('bank-transfers.index');
        Route::post('/refund-processes/{refundProcess}/bank-transfers/{transfer}/process', [RefundProcessController::class, 'processBankTransfer'])->name('bank-transfers.process');
        Route::post('/refund-processes/{refundProcess}/bank-transfers/{transfer}/reject', [RefundProcessController::class, 'rejectBankTransfer'])->name('bank-transfers.reject');
        
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
        return response()->json($request->user());
    });

    // ============================================================================
    // ANNOUNCEMENTS ROUTES
    // ============================================================================
    Route::apiResource('announcements', AnnouncementController::class);

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
