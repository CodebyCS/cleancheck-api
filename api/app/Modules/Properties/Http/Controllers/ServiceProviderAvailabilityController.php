<?php

namespace App\Modules\Properties\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Properties\Http\Requests\StoreServiceProviderUnavailabilityRequest;
use App\Modules\Properties\Http\Requests\UpdateServiceProviderAvailabilityRequest;
use App\Modules\Properties\Http\Resources\ServiceProviderAvailabilityResource;
use App\Modules\Properties\Http\Resources\ServiceProviderUnavailabilityResource;
use App\Modules\Properties\Models\ServiceProvider;
use App\Modules\Properties\Models\ServiceProviderUnavailability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class ServiceProviderAvailabilityController extends Controller
{
    // Weekly hours plus the unavailabilities that have not ended yet.
    public function show(Request $request, ServiceProvider $serviceProvider): JsonResponse
    {
        $this->authorizeViewer($request, $serviceProvider);

        return response()->json(['data' => $this->schedule($serviceProvider)]);
    }

    // Replaces the whole weekly schedule in one transaction.
    public function update(UpdateServiceProviderAvailabilityRequest $request, ServiceProvider $serviceProvider): JsonResponse
    {
        DB::transaction(function () use ($request, $serviceProvider): void {
            $serviceProvider->availabilities()->delete();
            $serviceProvider->availabilities()->createMany(
                collect($request->validated('intervals'))
                    ->map(fn (array $interval) => [
                        'weekday' => (int) $interval['weekday'],
                        'start_time' => $interval['start_time'],
                        'end_time' => $interval['end_time'],
                    ])
                    ->all(),
            );
        });

        return response()->json(['data' => $this->schedule($serviceProvider)]);
    }

    public function storeUnavailability(StoreServiceProviderUnavailabilityRequest $request, ServiceProvider $serviceProvider): JsonResponse
    {
        $unavailability = $serviceProvider->unavailabilities()->create([
            'starts_at' => $request->startsAt(),
            'ends_at' => $request->endsAt(),
            'reason' => $request->validated('reason'),
        ]);

        return (new ServiceProviderUnavailabilityResource($unavailability))
            ->response()
            ->setStatusCode(201);
    }

    public function destroyUnavailability(
        Request $request,
        ServiceProvider $serviceProvider,
        ServiceProviderUnavailability $unavailability,
    ): Response {
        $this->authorizeViewer($request, $serviceProvider);
        abort_unless($unavailability->service_provider_id === $serviceProvider->id, 404);

        $unavailability->delete();

        return response()->noContent();
    }

    private function authorizeViewer(Request $request, ServiceProvider $serviceProvider): void
    {
        $user = $request->user();

        abort_unless($user->hasRole('admin', 'supervisor') || $user->id === $serviceProvider->user_id, 403);
    }

    /**
     * @return array<string, mixed>
     */
    private function schedule(ServiceProvider $serviceProvider): array
    {
        $weekly = $serviceProvider->availabilities()
            ->orderBy('weekday')
            ->orderBy('start_time')
            ->get();

        $unavailabilities = $serviceProvider->unavailabilities()
            ->where('ends_at', '>', now())
            ->orderBy('starts_at')
            ->get();

        return [
            'service_provider_id' => $serviceProvider->id,
            'weekly' => ServiceProviderAvailabilityResource::collection($weekly)->resolve(),
            'unavailabilities' => ServiceProviderUnavailabilityResource::collection($unavailabilities)->resolve(),
        ];
    }
}
