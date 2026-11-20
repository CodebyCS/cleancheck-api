<?php

namespace App\Modules\Properties\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Properties\Http\Requests\StoreZoneRequest;
use App\Modules\Properties\Http\Requests\UpdateZoneRequest;
use App\Modules\Properties\Http\Resources\ZoneResource;
use App\Modules\Properties\Models\Zone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ZoneController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $zones = Zone::query()
            ->withCount(['properties', 'serviceProviders'])
            ->when(
                $request->has('active'),
                fn ($query) => $query->where('is_active', $request->boolean('active')),
            )
            ->orderBy('name')
            ->get();

        return ZoneResource::collection($zones);
    }

    public function store(StoreZoneRequest $request): JsonResponse
    {
        $zone = Zone::create($request->validated());

        return (new ZoneResource($zone->loadCount(['properties', 'serviceProviders'])))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateZoneRequest $request, Zone $zone): ZoneResource
    {
        $zone->update($request->validated());

        return new ZoneResource($zone->fresh()->loadCount(['properties', 'serviceProviders']));
    }

    public function activate(Zone $zone): ZoneResource
    {
        return $this->setActive($zone, true);
    }

    // Deactivating keeps the links to properties and providers for history;
    // inactive zones can no longer be chosen for new links.
    public function deactivate(Zone $zone): ZoneResource
    {
        return $this->setActive($zone, false);
    }

    private function setActive(Zone $zone, bool $active): ZoneResource
    {
        $zone->update(['is_active' => $active]);

        return new ZoneResource($zone->fresh()->loadCount(['properties', 'serviceProviders']));
    }
}
