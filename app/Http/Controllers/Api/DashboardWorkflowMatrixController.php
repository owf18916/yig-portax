<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DashboardWorkflowMatrixRequest;
use App\Services\TaxWorkflowMatrix\TaxWorkflowMatrixService;

class DashboardWorkflowMatrixController extends Controller
{
    public function __invoke(DashboardWorkflowMatrixRequest $request, TaxWorkflowMatrixService $service)
    {
        return response()->json([
            'success' => true,
            'data' => $service->build($request->user(), $request->filters()),
        ]);
    }
}
