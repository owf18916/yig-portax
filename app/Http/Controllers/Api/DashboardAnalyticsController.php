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
     * HOLDING users see all entities, AFFILIATE users see only their own entity
     */
    public function openCasesPerEntity(Request $request)
    {
        try {
            $user = auth()->user();
            
            // Get accessible entities based on user's entity_type
            if ($user && $user->entity && $user->entity->entity_type !== 'HOLDING') {
                // AFFILIATE users: only see their own entity
                $entities = Entity::where('is_active', true)
                    ->where('id', $user->entity_id)
                    ->orderBy('name')
                    ->get();
            } else {
                // HOLDING users or admin: see all active entities
                $entities = Entity::where('is_active', true)
                    ->orderBy('name')
                    ->get();
            }

            $data = [];

            foreach ($entities as $entity) {
                // Get count of incomplete cases by type (aggregate all currencies)
                $openCases = TaxCase::where('entity_id', $entity->id)
                    ->where('is_completed', false)
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
                    'currencies' => [
                        [
                            'code' => 'ALL',
                            'name' => 'All Currencies',
                            'symbol' => '',
                            'cit' => $citCount,
                            'vat' => $vatCount
                        ]
                    ]
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
     * Get disputed amount data grouped by entity, currency and case type
     * Returns data for stacked bar chart showing CIT vs VAT disputed amounts
     * HOLDING users see all entities, AFFILIATE users see only their own entity
     */
    public function disputedAmountPerEntity(Request $request)
    {
        try {
            $user = auth()->user();
            
            // Get accessible entities based on user's entity_type
            if ($user && $user->entity && $user->entity->entity_type !== 'HOLDING') {
                // AFFILIATE users: only see their own entity
                $entities = Entity::where('is_active', true)
                    ->where('id', $user->entity_id)
                    ->orderBy('name')
                    ->get();
            } else {
                // HOLDING users or admin: see all active entities
                $entities = Entity::where('is_active', true)
                    ->orderBy('name')
                    ->get();
            }

            $data = [];

            foreach ($entities as $entity) {
                // Get sum of disputed amounts by type and currency for incomplete cases
                $disputedAmounts = TaxCase::where('entity_id', $entity->id)
                    ->where('is_completed', false)
                    ->with('currency')
                    ->selectRaw('currency_id, case_type, SUM(disputed_amount) as total_amount')
                    ->groupBy('currency_id', 'case_type')
                    ->get();

                // Group by currency
                $currenciesMap = [];
                
                foreach ($disputedAmounts as $case) {
                    if (!$case->currency) continue;
                    
                    $currencyCode = $case->currency->code;
                    
                    if (!isset($currenciesMap[$currencyCode])) {
                        $currenciesMap[$currencyCode] = [
                            'code' => $case->currency->code,
                            'name' => $case->currency->name,
                            'symbol' => $case->currency->symbol,
                            'cit' => 0,
                            'vat' => 0
                        ];
                    }
                    
                    if ($case->case_type === 'CIT') {
                        $currenciesMap[$currencyCode]['cit'] = (float) $case->total_amount;
                    } elseif ($case->case_type === 'VAT') {
                        $currenciesMap[$currencyCode]['vat'] = (float) $case->total_amount;
                    }
                }

                $data[] = [
                    'entity' => $entity->name,
                    'entity_id' => $entity->id,
                    'currencies' => array_values($currenciesMap)
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
     * HOLDING users see all entities, AFFILIATE users see only their own entity
     */
    public function dashboardCharts(Request $request)
    {
        try {
            $user = auth()->user();
            
            // Get accessible entities based on user's entity_type
            if ($user && $user->entity && $user->entity->entity_type !== 'HOLDING') {
                // AFFILIATE users: only see their own entity
                $entities = Entity::where('is_active', true)
                    ->where('id', $user->entity_id)
                    ->orderBy('name')
                    ->get();
            } else {
                // HOLDING users or admin: see all active entities
                $entities = Entity::where('is_active', true)
                    ->orderBy('name')
                    ->get();
            }

            $openCasesData = [];
            $disputedAmountData = [];

            foreach ($entities as $entity) {
                // Open cases data - simple count (no currency grouping)
                $openCases = TaxCase::where('entity_id', $entity->id)
                    ->where('is_completed', false)
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
                    'currencies' => [
                        [
                            'code' => 'ALL',
                            'name' => 'All Currencies',
                            'symbol' => '',
                            'cit' => $citCaseCount,
                            'vat' => $vatCaseCount
                        ]
                    ]
                ];

                // Disputed amount data - group by currency
                $disputedAmounts = TaxCase::where('entity_id', $entity->id)
                    ->where('is_completed', false)
                    ->with('currency')
                    ->selectRaw('currency_id, case_type, SUM(disputed_amount) as total_amount')
                    ->groupBy('currency_id', 'case_type')
                    ->get();

                $currenciesMapAmounts = [];
                foreach ($disputedAmounts as $case) {
                    if (!$case->currency) continue;
                    
                    $currencyCode = $case->currency->code;
                    
                    if (!isset($currenciesMapAmounts[$currencyCode])) {
                        $currenciesMapAmounts[$currencyCode] = [
                            'code' => $case->currency->code,
                            'name' => $case->currency->name,
                            'symbol' => $case->currency->symbol,
                            'cit' => 0,
                            'vat' => 0
                        ];
                    }
                    
                    if ($case->case_type === 'CIT') {
                        $currenciesMapAmounts[$currencyCode]['cit'] = (float) $case->total_amount;
                    } elseif ($case->case_type === 'VAT') {
                        $currenciesMapAmounts[$currencyCode]['vat'] = (float) $case->total_amount;
                    }
                }

                $disputedAmountData[] = [
                    'entity' => $entity->name,
                    'entity_id' => $entity->id,
                    'currencies' => array_values($currenciesMapAmounts)
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
}
