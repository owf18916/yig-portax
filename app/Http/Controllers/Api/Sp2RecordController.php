<?php

namespace App\Http\Controllers\Api;

use App\Models\TaxCase;
use App\Models\Sp2Record;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class Sp2RecordController extends ApiController
{
    /**
     * Store SP2 record (Stage 2) via workflow endpoint
     */
    public function store(Request $request, TaxCase $taxCase): JsonResponse
    {
        $validated = $request->validate([
            'sp2_number' => 'nullable|string',
            'issue_date' => 'nullable|date',
            'receipt_date' => 'nullable|date',
            'auditor_name' => 'nullable|string',
            'auditor_phone' => 'nullable|string',
            'auditor_email' => 'nullable|email',
            'notes' => 'nullable|string',
        ]);

        $validated['tax_case_id'] = $taxCase->id;

        // Get or create SP2 record
        $sp2Record = Sp2Record::updateOrCreate(
            ['tax_case_id' => $taxCase->id],
            $validated
        );

        return $this->success(
            $sp2Record,
            'SP2 record saved successfully',
            201
        );
    }

    /**
     * Get SP2 record for a tax case
     */
    public function show(TaxCase $taxCase): JsonResponse
    {
        $sp2Record = $taxCase->sp2Record;

        if (!$sp2Record) {
            return $this->error('No SP2 record found for this tax case', 404);
        }

        return $this->success($sp2Record);
    }
}
