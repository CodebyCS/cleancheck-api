<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Http\Requests\MonthlyReportRequest;
use App\Modules\Billing\Reports\MonthlyReport;
use App\Modules\Billing\Reports\MonthlyReportCsv;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class MonthlyReportController extends Controller
{
    // Monthly settlement per provider. "apurado" is a calculation, not a payment confirmation.
    public function show(MonthlyReportRequest $request, MonthlyReport $report): JsonResponse
    {
        return response()->json([
            'data' => $report->build($request->month(), $request->serviceProviderId()),
        ]);
    }

    // Same report as a CSV file (detail rows, then totals per provider and overall).
    public function export(MonthlyReportRequest $request, MonthlyReport $report, MonthlyReportCsv $csv): Response
    {
        $month = $request->month();
        $data = $report->build($month, $request->serviceProviderId());

        return response($csv->content($data), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="relatorio-mensal-'.$month.'.csv"',
        ]);
    }
}
