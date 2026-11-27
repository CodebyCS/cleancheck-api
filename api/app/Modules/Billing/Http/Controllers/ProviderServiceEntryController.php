<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Http\Requests\ProviderMonthlySummaryRequest;
use App\Modules\Billing\Models\ServiceEntry;
use App\Modules\Billing\Models\ServiceEntryAdjustment;
use App\Modules\Billing\Support\Cents;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class ProviderServiceEntryController extends Controller
{
    /**
     * Monthly summary for the logged-in provider: only their own entries, with final amounts.
     * Who made each adjustment is not shown, only the amount and the reason.
     */
    public function summary(ProviderMonthlySummaryRequest $request): JsonResponse
    {
        $providerId = $request->user()?->serviceProvider?->id;

        abort_if($providerId === null, 403, 'Utilizador sem perfil de prestador.');

        $month = $request->validated('month') ?? now()->format('Y-m');
        $start = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfDay();

        $entries = ServiceEntry::query()
            ->with(['property:id,name', 'adjustments'])
            ->where('service_provider_id', $providerId)
            // Half-open range: service_date may be stored with a time part (e.g. SQLite).
            ->where('service_date', '>=', $start->toDateString())
            ->where('service_date', '<', $start->copy()->addMonth()->toDateString())
            ->orderBy('service_date')
            ->orderBy('id')
            ->get();

        $totals = $entries
            ->groupBy('currency')
            ->sortKeys()
            ->map(function ($group, string $currency) {
                $original = $group->sum(fn (ServiceEntry $entry) => Cents::from($entry->amount));
                $adjustments = $group->sum(fn (ServiceEntry $entry) => Cents::from($entry->adjustmentsTotal()));

                return [
                    'currency' => $currency,
                    'entries' => $group->count(),
                    'original_total' => Cents::format($original),
                    'adjustments_total' => Cents::format($adjustments),
                    'total' => Cents::format($original + $adjustments),
                ];
            })
            ->values();

        return response()->json([
            'data' => [
                'month' => $month,
                'totals' => $totals,
                'entries' => $entries->map(fn (ServiceEntry $entry) => [
                    'id' => $entry->id,
                    'task_id' => $entry->task_id,
                    'property_name' => $entry->property?->name,
                    'task_type' => $entry->task_type,
                    'service_date' => $entry->service_date?->toDateString(),
                    'amount' => $entry->amount,
                    'adjustments_total' => $entry->adjustmentsTotal(),
                    'final_amount' => $entry->finalAmount(),
                    'currency' => $entry->currency,
                    'adjustments' => $entry->adjustments->map(fn (ServiceEntryAdjustment $adjustment) => [
                        'amount' => $adjustment->amount,
                        'reason' => $adjustment->reason,
                        'created_at' => $adjustment->created_at?->toIso8601String(),
                    ])->values(),
                ])->values(),
            ],
        ]);
    }
}
