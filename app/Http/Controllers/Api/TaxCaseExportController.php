<?php

namespace App\Http\Controllers\Api;

use App\Exports\TaxCasesExport;
use App\Services\TaxCaseListFilters;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Facades\Excel;

class TaxCaseExportController
{
    /**
     * Export filtered tax cases to Excel
     * Groups by entity, creates multiple sheets
     */
    public function export(Request $request)
    {
        $filters = app(TaxCaseListFilters::class);
        $validated = $filters->validate($request);

        try {
            // Get authenticated user
            $user = Auth::user();
            if (! $user) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
            }

            // Build query with all relationships needed for export
            $query = $filters->authorized($user)
                ->with([
                    'entity',
                    'fiscalYear',
                    'currency',
                    'period',
                    'workflowHistories' => function ($q) {
                        $q->orderBy('stage_id')->orderBy('created_at', 'desc');
                    },
                    'sp2Record',
                    'sphpRecord',
                    'skpRecord',
                    'objectionSubmission',
                    'spuhRecord',
                    'objectionDecision',
                    'appealSubmission',
                    'appealExplanationRequest',
                    'appealDecision',
                    'supremeCourtSubmission',
                    'supremeCourtDecision',
                    'kianSubmission',
                ]);

            $filters->apply($query, $validated);

            // Get filtered cases
            $taxCases = $query->get();

            if ($taxCases->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tax cases found with current filters. Try removing some filters.',
                ], 404);
            }

            // Generate filename with timestamp
            $filename = 'tax-cases-export-'.now()->format('Y-m-d-His').'.xlsx';

            // Create and download excel file
            return Excel::download(new TaxCasesExport($taxCases), $filename);

        } catch (\Throwable $e) {
            // Workbook failures can contain private data. Do not report their diagnostics.
            return response()->json([
                'success' => false,
                'message' => 'Failed to export tax cases.',
            ], 500);
        }
    }
}
