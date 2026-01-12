<?php

namespace App\Http\Controllers\Api;

use App\Models\FiscalYear;
use Illuminate\Http\JsonResponse;

class FiscalYearController extends ApiController
{
    /**
     * Display a listing of all fiscal years
     */
    public function index(): JsonResponse
    {
        $fiscalYears = FiscalYear::select('id', 'year', 'start_date', 'end_date')
            ->orderBy('year', 'desc')
            ->get();

        return $this->success($fiscalYears);
    }

    /**
     * Display a specific fiscal year
     */
    public function show(FiscalYear $fiscalYear): JsonResponse
    {
        return $this->success($fiscalYear);
    }
}
