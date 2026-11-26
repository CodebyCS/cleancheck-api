<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Http\Requests\StoreServiceEntryAdjustmentRequest;
use App\Modules\Billing\Http\Resources\ServiceEntryAdjustmentResource;
use App\Modules\Billing\Models\ServiceEntry;
use App\Modules\Billing\Services\RecordServiceEntryAdjustment;
use Illuminate\Http\JsonResponse;

class ServiceEntryAdjustmentController extends Controller
{
    // There is no update or delete: a wrong adjustment is corrected with another adjustment.
    public function store(
        StoreServiceEntryAdjustmentRequest $request,
        int $serviceEntry,
        RecordServiceEntryAdjustment $adjustments,
    ): JsonResponse {
        $adjustment = $adjustments->handle(
            $serviceEntry,
            (string) $request->validated('amount'),
            $request->validated('reason'),
            $request->user(),
        );

        $entry = ServiceEntry::query()->with('adjustments')->findOrFail($serviceEntry);

        return (new ServiceEntryAdjustmentResource($adjustment->load('createdBy:id,name')))
            ->additional(['meta' => [
                'currency' => $entry->currency,
                'original_amount' => $entry->amount,
                'adjustments_total' => $entry->adjustmentsTotal(),
                'final_amount' => $entry->finalAmount(),
            ]])
            ->response()
            ->setStatusCode(201);
    }
}
