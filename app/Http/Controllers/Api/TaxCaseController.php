<?php

namespace App\Http\Controllers\Api;

use App\Models\TaxCase;
use App\Models\Entity;
use App\Models\CaseStatus;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class TaxCaseController extends ApiController
{
    /**
     * Display a listing of tax cases
     */
    public function index(Request $request): JsonResponse
    {
        $query = TaxCase::with([
            'entity',
            'fiscalYear',
            'period',
            'status',
            'currency',
            'user'
        ]);

        // Filter by entity (multi-company support)
        if ($request->has('entity_id')) {
            $query->where('entity_id', $request->entity_id);
        }

        // Filter by status
        if ($request->has('status_id')) {
            $query->where('case_status_id', $request->status_id);
        }

        // Filter by case type
        if ($request->has('case_type')) {
            $query->where('case_type', $request->case_type);
        }

        // Filter by current stage
        if ($request->has('current_stage')) {
            $query->where('current_stage', $request->current_stage);
        }

        // Search by case number or SPT number
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('case_number', 'like', "%{$search}%")
                  ->orWhere('spt_number', 'like', "%{$search}%");
            });
        }

        $taxCases = $query->orderBy('created_at', 'desc')->paginate(15);

        return $this->success($taxCases);
    }

    /**
     * Store a newly created tax case
     */
    public function store(Request $request): JsonResponse
    {
        $user = auth()->user();
        
        if (!$user) {
            return $this->error('Unauthorized', 401);
        }

        $validated = $request->validate([
            'entity_id' => 'required|exists:entities,id',
            'case_type' => 'required|in:CIT,VAT',
            'fiscal_year_id' => 'nullable|exists:fiscal_years,id',
            'period' => 'nullable|string',
            'case_number' => 'required|string|unique:tax_cases',
            'disputed_amount' => 'required|numeric|min:0',
            'spt_number' => 'nullable|string|unique:tax_cases,spt_number',
            'filing_date' => 'nullable|date',
            'received_date' => 'nullable|date',
            'reported_amount' => 'nullable|numeric|min:0',
            'vat_in_amount' => 'nullable|numeric|min:0',
            'vat_out_amount' => 'nullable|numeric|min:0',
            'period_id' => 'nullable|exists:periods,id',
            'description' => 'nullable|string',
        ]);

        // Verify user can create case for this entity
        // Admin dapat membuat untuk entity apapun, non-admin hanya untuk entity mereka
        if ($user->role_id !== 1 && $validated['entity_id'] != $user->entity_id) {
            return $this->error('You can only create cases for your assigned entity', 403);
        }

        // Auto-generate SPT number if not provided
        if (!isset($validated['spt_number']) || empty($validated['spt_number'])) {
            $validated['spt_number'] = $this->generateSptNumber($validated['entity_id'], $validated['case_type']);
        }

        // Set filing date to today if not provided
        if (!isset($validated['filing_date']) || empty($validated['filing_date'])) {
            $validated['filing_date'] = now()->toDateString();
        }

        // Set reported amount to disputed amount if not provided
        if (!isset($validated['reported_amount']) || empty($validated['reported_amount'])) {
            $validated['reported_amount'] = $validated['disputed_amount'];
        }

        // Set user and system defaults
        $validated['user_id'] = $user->id;
        $validated['current_stage'] = 1;
        $validated['case_status_id'] = 1; // OPEN status
        $validated['currency_id'] = 1; // IDR default

        // Remove fields that don't belong to the table
        unset($validated['period']);

        $taxCase = TaxCase::create($validated);

        return $this->success(
            $taxCase->load(['entity', 'fiscalYear', 'period', 'status']),
            'Tax case created successfully',
            201
        );
    }

    /**
     * Display the specified tax case
     */
    public function show(TaxCase $taxCase): JsonResponse
    {
        $taxCase->load([
            'entity',
            'fiscalYear',
            'period',
            'status',
            'currency',
            'user',
            'submittedBy',
            'approvedBy',
            'sp2Record',
            'sphpRecord',
            'skpRecord',
            'objectionSubmission',
            'objectionDecision',
            'appealSubmission',
            'appealDecision',
            'supremeCourtDecision',
            'refundProcess',
            'workflowHistories',
            'documents'
        ]);

        return $this->success($taxCase);
    }

    /**
     * Update the specified tax case
     */
    public function update(Request $request, TaxCase $taxCase): JsonResponse
    {
        // Only allow updates if case is not completed
        if ($taxCase->is_completed) {
            return $this->error('Cannot update a completed tax case', 422);
        }

        $validated = $request->validate([
            'disputed_amount' => 'sometimes|numeric|min:0',
            'description' => 'sometimes|string',
            'received_date' => 'sometimes|date',
        ]);

        $taxCase->update($validated);

        return $this->success(
            $taxCase->fresh(['entity', 'fiscalYear', 'status']),
            'Tax case updated successfully'
        );
    }

    /**
     * Get tax case workflow history
     */
    public function workflowHistory(TaxCase $taxCase): JsonResponse
    {
        $history = $taxCase->workflowHistories()
            ->with('user')
            ->orderBy('created_at', 'desc')
            ->get();

        return $this->success($history);
    }

    /**
     * Get all documents for a tax case
     */
    public function documents(TaxCase $taxCase): JsonResponse
    {
        $documents = $taxCase->documents()
            ->with(['uploadedBy', 'verifiedBy'])
            ->orderBy('created_at', 'desc')
            ->get();

        return $this->success($documents);
    }

    /**
     * Mark case as completed (final stage)
     */
    public function complete(Request $request, TaxCase $taxCase): JsonResponse
    {
        if ($taxCase->current_stage !== 12) {
            return $this->error('Tax case can only be completed at final stage', 422);
        }

        $taxCase->update([
            'is_completed' => true,
            'completed_date' => now(),
        ]);

        // Log workflow completion
        $taxCase->workflowHistories()->create([
            'stage_from' => 11,
            'stage_to' => 12,
            'action' => 'completed',
            'user_id' => auth()->id(),
            'created_at' => now(),
        ]);

        return $this->success(
            $taxCase->fresh(),
            'Tax case completed successfully'
        );
    }

    /**
     * Generate unique case number
     */
    private function generateCaseNumber(int $entityId): string
    {
        $entity = Entity::find($entityId);
        $year = date('Y');
        $count = TaxCase::where('entity_id', $entityId)
            ->whereYear('created_at', $year)
            ->count() + 1;

        return sprintf(
            'TAX-%s-%04d',
            $entity->code,
            $count
        );
    }

    /**
     * Generate unique SPT number
     */
    private function generateSptNumber(int $entityId, string $caseType): string
    {
        $entity = Entity::find($entityId);
        $year = date('Y');
        $typeCode = $caseType === 'CIT' ? 'C' : 'V';
        $count = TaxCase::where('entity_id', $entityId)
            ->where('case_type', $caseType)
            ->whereYear('created_at', $year)
            ->count() + 1;

        return sprintf(
            'SPT%s%s%04d',
            $typeCode,
            $year,
            $count
        );
    }
}
