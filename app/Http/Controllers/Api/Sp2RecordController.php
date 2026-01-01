<?php

namespace App\Http\Controllers\Api;

use App\Models\TaxCase;
use App\Models\Sp2Record;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class Sp2RecordController extends ApiController
{
    /**
     * Store SP2 record (Stage 2)
     */
    public function store(Request $request, TaxCase $taxCase): JsonResponse
    {
        // Validate stage
        if ($taxCase->current_stage !== 2) {
            return $this->error('Tax case is not at SP2 stage', 422);
        }

        $validated = $request->validate([
            'sp2_number' => 'required|string|unique:sp2_records',
            'issue_date' => 'required|date',
            'receipt_date' => 'nullable|date',
            'auditor_name' => 'required|string',
            'auditor_title' => 'required|string',
            'auditor_department' => 'required|string',
            'findings' => 'required|string',
            'notes' => 'nullable|string',
        ]);

        $validated['tax_case_id'] = $taxCase->id;
        $validated['submitted_by'] = auth()->id();
        $validated['submitted_at'] = now();
        $validated['status'] = 'submitted';

        $sp2Record = Sp2Record::create($validated);

        // Log workflow
        $taxCase->workflowHistories()->create([
            'stage_from' => 1,
            'stage_to' => 2,
            'action' => 'submitted',
            'user_id' => auth()->id(),
            'created_at' => now(),
        ]);

        return $this->success(
            $sp2Record->load(['taxCase', 'submittedBy']),
            'SP2 record submitted successfully',
            201
        );
    }

    /**
     * Approve SP2 record
     */
    public function approve(Request $request, TaxCase $taxCase, Sp2Record $sp2Record): JsonResponse
    {
        if ($sp2Record->tax_case_id !== $taxCase->id) {
            return $this->error('SP2 record does not belong to this tax case', 422);
        }

        if ($sp2Record->status === 'approved') {
            return $this->error('SP2 record already approved', 422);
        }

        $validated = $request->validate([
            'notes' => 'nullable|string',
        ]);

        $sp2Record->update([
            'approved_by' => auth()->id(),
            'approved_at' => now(),
            'status' => 'approved',
            'notes' => $validated['notes'] ?? $sp2Record->notes,
        ]);

        // Update tax case to next stage
        $taxCase->update([
            'current_stage' => 3,
        ]);

        // Log workflow
        $taxCase->workflowHistories()->create([
            'stage_from' => 2,
            'stage_to' => 3,
            'action' => 'approved',
            'user_id' => auth()->id(),
            'created_at' => now(),
        ]);

        return $this->success(
            $sp2Record->fresh(['taxCase', 'approvedBy']),
            'SP2 record approved and case moved to SPHP stage'
        );
    }

    /**
     * Reject SP2 record
     */
    public function reject(Request $request, TaxCase $taxCase, Sp2Record $sp2Record): JsonResponse
    {
        if ($sp2Record->tax_case_id !== $taxCase->id) {
            return $this->error('SP2 record does not belong to this tax case', 422);
        }

        $validated = $request->validate([
            'notes' => 'required|string|min:10',
        ]);

        $sp2Record->update([
            'status' => 'rejected',
            'notes' => $validated['notes'],
        ]);

        return $this->success(
            $sp2Record->fresh(),
            'SP2 record rejected'
        );
    }

    /**
     * Get SP2 record for a tax case
     */
    public function show(TaxCase $taxCase): JsonResponse
    {
        $sp2Record = $taxCase->sp2Record()->with(['submittedBy', 'approvedBy'])->first();

        if (!$sp2Record) {
            return $this->error('No SP2 record found for this tax case', 404);
        }

        return $this->success($sp2Record);
    }
}
