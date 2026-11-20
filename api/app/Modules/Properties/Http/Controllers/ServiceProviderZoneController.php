<?php

namespace App\Modules\Properties\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Properties\Http\Requests\UpdateServiceProviderZonesRequest;
use App\Modules\Properties\Http\Resources\ZoneResource;
use App\Modules\Properties\Models\ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ServiceProviderZoneController extends Controller
{
    // Admin, supervisor or the provider themself can see the zones.
    public function index(Request $request, ServiceProvider $serviceProvider): AnonymousResourceCollection
    {
        $viewer = $request->user();
        abort_unless($viewer->hasRole('admin', 'supervisor') || $viewer->id === $serviceProvider->user_id, 403);

        return ZoneResource::collection($serviceProvider->zones()->orderBy('name')->get());
    }

    // Replaces the provider's zones with the list sent.
    public function update(UpdateServiceProviderZonesRequest $request, ServiceProvider $serviceProvider): AnonymousResourceCollection
    {
        $serviceProvider->zones()->sync($request->validated('zone_ids'));

        return ZoneResource::collection($serviceProvider->zones()->orderBy('name')->get());
    }
}
