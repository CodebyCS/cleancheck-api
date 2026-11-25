<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Http\Requests\StoreRateRequest;
use App\Modules\Billing\Http\Resources\RateResource;
use App\Modules\Billing\Models\Rate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RateController extends Controller
{
    // Full history, newest first. ?property_id=general returns only the general rates.
    public function index(Request $request): AnonymousResourceCollection
    {
        $rates = Rate::query()
            ->when($request->filled('task_type'), fn ($query) => $query->where('task_type', $request->string('task_type')))
            ->when($request->input('property_id') === 'general', fn ($query) => $query->whereNull('property_id'))
            ->when(
                is_numeric($request->input('property_id')),
                fn ($query) => $query->where('property_id', (int) $request->input('property_id')),
            )
            ->orderByDesc('valid_from')
            ->orderByDesc('id')
            ->get();

        return RateResource::collection($rates);
    }

    // There is no update or delete: a new rate replaces the previous one from valid_from onwards.
    public function store(StoreRateRequest $request): JsonResponse
    {
        $rate = Rate::create([
            ...$request->validated(),
            'currency' => $request->validated('currency') ?? Rate::DEFAULT_CURRENCY,
            'created_by_user_id' => $request->user()->id,
        ]);

        return (new RateResource($rate))
            ->response()
            ->setStatusCode(201);
    }
}
