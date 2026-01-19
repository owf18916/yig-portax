<?php

namespace App\Http\Controllers\Api;

use App\Models\TaxCase;
use App\Models\SpuhRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SpuhRecordController extends ApiController
{
    /**
     * Store/Update SPUH record (Stage 6) - Multi-phase approach
     * Phase 1: spuh_number, issue_date, receipt_date (required)
     * Phase 2: reply_number, reply_date (optional, filled later)
     */
    public function store(Request $request, TaxCase $taxCase)
    {
        try {
            $validated = $request->validate([
                'spuh_number' => 'required|string',
                'issue_date' => 'required|date',
                'receipt_date' => 'required|date',
                'reply_number' => 'nullable|string',
                'reply_date' => 'nullable|date',
                'notes' => 'nullable|string',
            ]);

            DB::beginTransaction();

            // Use updateOrCreate for partial updates (handles both Phase 1 and Phase 2)
            $spuhRecord = SpuhRecord::updateOrCreate(
                ['tax_case_id' => $taxCase->id],
                [
                    'spuh_number' => $validated['spuh_number'],
                    'issue_date' => $validated['issue_date'],
                    'receipt_date' => $validated['receipt_date'],
                    'reply_number' => $validated['reply_number'] ?? null,
                    'reply_date' => $validated['reply_date'] ?? null,
                    'status' => 'submitted',
                    'notes' => $validated['notes'] ?? null,
                ]
            );

            // Update tax case status
            $taxCase->update([
                'current_stage' => 6,
                'case_status_id' => 2, // SUBMITTED status
            ]);

            DB::commit();

            return $this->success($spuhRecord, 'SPUH record saved successfully', 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Display the specified SPUH record
     */
    public function show(TaxCase $taxCase)
    {
        try {
            $spuhRecord = $taxCase->spuhRecord;

            if (!$spuhRecord) {
                return $this->error('SPUH record not found for this tax case', 404);
            }

            return $this->success($spuhRecord, 'SPUH record retrieved');
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }
}

