<?php

namespace App\Http\Controllers\Api;

use App\Models\TaxCase;
use App\Models\SphpRecord;
use App\Models\WorkflowHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SphpRecordController extends ApiController
{
    /**
     * Store a newly created SPHP record (Stage 3)
     */
    public function store(Request $request, TaxCase $taxCase)
    {
        try {
            $validated = $request->validate([
                'sphp_number' => 'required|string|unique:sphp_records',
                'issued_date' => 'required|date',
                'summary' => 'required|string',
                'findings' => 'required|string',
                'recommended_action' => 'nullable|string',
                'notes' => 'nullable|string',
            ]);

            DB::beginTransaction();

            $sphpRecord = SphpRecord::create([
                'tax_case_id' => $taxCase->id,
                'sphp_number' => $validated['sphp_number'],
                'issued_date' => $validated['issued_date'],
                'summary' => $validated['summary'],
                'findings' => $validated['findings'],
                'recommended_action' => $validated['recommended_action'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'status' => 'DRAFT',
                'received_date' => now(),
            ]);

            // Log workflow history
            WorkflowHistory::create([
                'tax_case_id' => $taxCase->id,
                'stage_number' => 3,
                'action' => 'SPHP_DRAFT_CREATED',
                'description' => 'SPHP record created in draft status',
                'performed_by' => auth()->id(),
                'notes' => $validated['notes'] ?? null,
            ]);

            // Update tax case status
            $taxCase->update([
                'current_stage' => 3,
                'status' => 'SPHP_RECEIVED'
            ]);

            DB::commit();

            return $this->success($sphpRecord, 'SPHP record created successfully', 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Display the specified SPHP record
     */
    public function show(TaxCase $taxCase, SphpRecord $sphpRecord)
    {
        if ($sphpRecord->tax_case_id !== $taxCase->id) {
            return $this->error('SPHP record not found for this tax case', 404);
        }

        return $this->success($sphpRecord, 'SPHP record retrieved');
    }

    /**
     * Approve SPHP record and move to Stage 4 (SKP Records)
     */
    public function approve(Request $request, TaxCase $taxCase, SphpRecord $sphpRecord)
    {
        try {
            if ($sphpRecord->tax_case_id !== $taxCase->id) {
                return $this->error('SPHP record not found for this tax case', 404);
            }

            if ($sphpRecord->status !== 'DRAFT') {
                return $this->error('Only draft SPHP records can be approved', 400);
            }

            DB::beginTransaction();

            // Update SPHP record
            $sphpRecord->update([
                'status' => 'APPROVED',
                'approved_date' => now(),
                'approved_by' => auth()->id(),
            ]);

            // Log workflow history
            WorkflowHistory::create([
                'tax_case_id' => $taxCase->id,
                'stage_number' => 3,
                'action' => 'SPHP_APPROVED',
                'description' => 'SPHP record approved, moving to Stage 4 (SKP Records)',
                'performed_by' => auth()->id(),
                'notes' => $request->input('notes'),
                'next_stage' => 4,
            ]);

            // Update tax case status
            $taxCase->update([
                'current_stage' => 4,
                'status' => 'SKP_AWAITING'
            ]);

            DB::commit();

            return $this->success($sphpRecord, 'SPHP record approved, proceeding to Stage 4', 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Reject SPHP record
     */
    public function reject(Request $request, TaxCase $taxCase, SphpRecord $sphpRecord)
    {
        try {
            if ($sphpRecord->tax_case_id !== $taxCase->id) {
                return $this->error('SPHP record not found for this tax case', 404);
            }

            $validated = $request->validate([
                'rejection_reason' => 'required|string',
            ]);

            DB::beginTransaction();

            $sphpRecord->update([
                'status' => 'REJECTED',
                'rejection_reason' => $validated['rejection_reason'],
                'rejected_date' => now(),
                'rejected_by' => auth()->id(),
            ]);

            // Log workflow history
            WorkflowHistory::create([
                'tax_case_id' => $taxCase->id,
                'stage_number' => 3,
                'action' => 'SPHP_REJECTED',
                'description' => 'SPHP record rejected',
                'performed_by' => auth()->id(),
                'notes' => $validated['rejection_reason'],
            ]);

            // Tax case returns to SP2 stage
            $taxCase->update([
                'current_stage' => 2,
                'status' => 'SP2_REVISION_NEEDED'
            ]);

            DB::commit();

            return $this->success($sphpRecord, 'SPHP record rejected', 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error($e->getMessage(), 500);
        }
    }
}
