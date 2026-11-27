<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Http\Requests\MonthlyReportRequest;
use App\Modules\Billing\Reports\MonthlyReport;
use Illuminate\Http\JsonResponse;

class MonthlyReportController extends Controller
{
    // Monthly settlement per provider. "apurado" is a calculation, not a payment confirmation.
    public function show(MonthlyReportRequest $request, MonthlyReport $report): JsonResponse
    {
        return response()->json([
            'data' => $report->build($request->month(), $request->serviceProviderId()),
        ]);
    }
}
