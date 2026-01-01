<?php

namespace App\Http\Controllers\Api;

use App\Models\TaxCase;
use App\Models\SpuhRecord;
use App\Models\WorkflowHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SpuhRecordController extends ApiController
{
    /**
     * Store SPUH record - Tax authority's response to objection (Stage 6)
     */
    public function store(Request $request, TaxCase $taxCase)
    {
        try {
            $validated = $request->validate([
                'spuh_number' => 'required|string|unique:spuh_records',
                'issued_date' => 'required|date',
                'response_type' => 'required|in:PARTIAL_ACCEPTANCE,REJECTION,ACCEPTANCE',
                'accepted_amount' => 'required|numeric|min:0',
                'rejection_reason' => 'nullable|string',
                'notes' => 'nullable|string',
            ]);

            DB::beginTransaction();

            $spuhRecord = SpuhRecord::create([
                'tax_case_id' => $taxCase->id,
                'spuh_number' => $validated['spuh_number'],
                'issued_date' => $validated['issued_date'],
                'response_type' => $validated['response_type'],
                'accepted_amount' => $validated['accepted_amount'],
                'rejection_reason' => $validated['rejection_reason'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'status' => 'DRAFT',
                'received_date' => now(),
            ]);

            // Log workflow history
            WorkflowHistory::create([
                'tax_case_id' => $taxCase->id,
                'stage_number' => 6,
                'action' => 'SPUH_RECEIVED',
                'description' => 'SPUH (Tax authority response to objection) received',
                'performed_by' => auth()->id(),
                'notes' => $validated['notes'] ?? null,
            ]);

            // Update tax case status
            $taxCase->update([
                'current_stage' => 6,
                'status' => 'SPUH_RECEIVED'
            ]);

            DB::commit();

            return $this->success($spuhRecord, 'SPUH record created successfully', 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Display the specified SPUH record
     */
    public function show(TaxCase $taxCase, SpuhRecord $spuhRecord)
    {
        if ($spuhRecord->tax_case_id !== $taxCase->id) {
            return $this->error('SPUH record not found for this tax case', 404);
        }

        return $this->success($spuhRecord, 'SPUH record retrieved');
    }

    /**
     * Approve SPUH and move to Stage 7 (Objection Decision)
     */
    public function approve(Request $request, TaxCase $taxCase, SpuhRecord $spuhRecord)
    {
        try {
            if ($spuhRecord->tax_case_id !== $taxCase->id) {
                return $this->error('SPUH record not found for this tax case', 404);
            }

            if ($spuhRecord->status !== 'DRAFT') {
                return $this->error('Only draft SPUH records can be approved', 400);
            }

            DB::beginTransaction();

            $spuhRecord->update([
                'status' => 'APPROVED',
                'approved_date' => now(),
                'approved_by' => auth()->id(),
            ]);

            // Log workflow history
            WorkflowHistory::create([
                'tax_case_id' => $taxCase->id,
                'stage_number' => 6,
                'action' => 'SPUH_APPROVED',
                'description' => 'SPUH record approved, moving to Stage 7 (Objection Decision)',
                'performed_by' => auth()->id(),
                'notes' => $request->input('notes'),
                'next_stage' => 7,
            ]);

            // Update tax case status
            $taxCase->update([
                'current_stage' => 7,
                'status' => 'OBJECTION_DECISION_AWAITING'
            ]);

            DB::commit();

            return $this->success($spuhRecord, 'SPUH approved, proceeding to Stage 7', 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error($e->getMessage(), 500);
        }
    }
}
