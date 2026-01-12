<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\ApiController;
use App\Models\TaxCase;
use App\Models\Entity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardAnalyticsController extends ApiController
{
    /**
     * Get open cases data grouped by entity and case type
     * Returns data for stacked bar chart showing CIT vs VAT open cases
     */
    public function openCasesPerEntity(Request $request)
    {
        try {
            // Get all active entities
            $entities = Entity::where('is_active', true)
                ->orderBy('name')
                ->get();

            $data = [];

            foreach ($entities as $entity) {
                // Get count of open and draft cases by type
                $openCases = TaxCase::where('entity_id', $entity->id)
                    ->whereIn('case_status_id', $this->getOpenStatusIds())
                    ->selectRaw('case_type, COUNT(*) as count')
                    ->groupBy('case_type')
                    ->get();

                $citCount = 0;
                $vatCount = 0;

                foreach ($openCases as $case) {
                    if ($case->case_type === 'CIT') {
                        $citCount = $case->count;
                    } elseif ($case->case_type === 'VAT') {
                        $vatCount = $case->count;
                    }
                }

                $data[] = [
                    'entity' => $entity->name,
                    'entity_id' => $entity->id,
                    'cit' => $citCount,
                    'vat' => $vatCount,
                    'total' => $citCount + $vatCount
                ];
            }

            return response()->json([
                'success' => true,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve open cases data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get disputed amount data grouped by entity and case type
     * Returns data for stacked bar chart showing CIT vs VAT disputed amounts
     */
    public function disputedAmountPerEntity(Request $request)
    {
        try {
            // Get all active entities
            $entities = Entity::where('is_active', true)
                ->orderBy('name')
                ->get();

            $data = [];

            foreach ($entities as $entity) {
                // Get sum of disputed amounts by type for open/draft cases
                $disputedAmounts = TaxCase::where('entity_id', $entity->id)
                    ->whereIn('case_status_id', $this->getOpenStatusIds())
                    ->selectRaw('case_type, SUM(disputed_amount) as total_amount')
                    ->groupBy('case_type')
                    ->get();

                $citAmount = 0;
                $vatAmount = 0;

                foreach ($disputedAmounts as $case) {
                    if ($case->case_type === 'CIT') {
                        $citAmount = (float) $case->total_amount;
                    } elseif ($case->case_type === 'VAT') {
                        $vatAmount = (float) $case->total_amount;
                    }
                }

                $data[] = [
                    'entity' => $entity->name,
                    'entity_id' => $entity->id,
                    'cit' => $citAmount,
                    'vat' => $vatAmount,
                    'total' => $citAmount + $vatAmount
                ];
            }

            return response()->json([
                'success' => true,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve disputed amount data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get both charts data at once
     */
    public function dashboardCharts(Request $request)
    {
        try {
            $entities = Entity::where('is_active', true)
                ->orderBy('name')
                ->get();

            $openCasesData = [];
            $disputedAmountData = [];

            foreach ($entities as $entity) {
                // Open cases data
                $openCases = TaxCase::where('entity_id', $entity->id)
                    ->whereIn('case_status_id', $this->getOpenStatusIds())
                    ->selectRaw('case_type, COUNT(*) as count')
                    ->groupBy('case_type')
                    ->get();

                $citCaseCount = 0;
                $vatCaseCount = 0;

                foreach ($openCases as $case) {
                    if ($case->case_type === 'CIT') {
                        $citCaseCount = $case->count;
                    } elseif ($case->case_type === 'VAT') {
                        $vatCaseCount = $case->count;
                    }
                }

                $openCasesData[] = [
                    'entity' => $entity->name,
                    'entity_id' => $entity->id,
                    'cit' => $citCaseCount,
                    'vat' => $vatCaseCount,
                    'total' => $citCaseCount + $vatCaseCount
                ];

                // Disputed amount data
                $disputedAmounts = TaxCase::where('entity_id', $entity->id)
                    ->whereIn('case_status_id', $this->getOpenStatusIds())
                    ->selectRaw('case_type, SUM(disputed_amount) as total_amount')
                    ->groupBy('case_type')
                    ->get();

                $citAmount = 0;
                $vatAmount = 0;

                foreach ($disputedAmounts as $case) {
                    if ($case->case_type === 'CIT') {
                        $citAmount = (float) $case->total_amount;
                    } elseif ($case->case_type === 'VAT') {
                        $vatAmount = (float) $case->total_amount;
                    }
                }

                $disputedAmountData[] = [
                    'entity' => $entity->name,
                    'entity_id' => $entity->id,
                    'cit' => $citAmount,
                    'vat' => $vatAmount,
                    'total' => $citAmount + $vatAmount
                ];
            }

            return response()->json([
                'success' => true,
                'openCases' => $openCasesData,
                'disputedAmounts' => $disputedAmountData
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve dashboard charts data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get IDs of open/draft statuses
     * This should match your case status definitions
     */
    private function getOpenStatusIds()
    {
        // This gets status IDs where cases are considered "open" (draft, open, in progress)
        // Adjust the codes based on your actual database values
        return DB::table('case_statuses')
            ->whereIn('code', ['draft', 'open', 'in_progress', 'pending_approval'])
            ->pluck('id')
            ->toArray();
    }
}
