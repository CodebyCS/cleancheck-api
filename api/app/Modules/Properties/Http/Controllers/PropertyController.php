<?php

namespace App\Modules\Properties\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Properties\Http\Requests\StorePropertyRequest;
use App\Modules\Properties\Http\Requests\UpdatePropertyRequest;
use App\Modules\Properties\Http\Resources\PropertyResource;
use App\Modules\Properties\Models\Property;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PropertyController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->hasRole('admin', 'supervisor'), 403);

        $properties = Property::query()
            ->when(
                $request->has('active'),
                fn ($query) => $query->where('is_active', $request->boolean('active')),
            )
            ->orderBy('name')
            ->get();

        return PropertyResource::collection($properties);
    }

    public function store(StorePropertyRequest $request): JsonResponse
    {
        $property = Property::create($request->validated());

        return (new PropertyResource($property))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdatePropertyRequest $request, Property $property): PropertyResource
    {
        $property->update($request->validated());

        return new PropertyResource($property->fresh());
    }

    public function activate(Request $request, Property $property): PropertyResource
    {
        return $this->setActive($request, $property, true);
    }

    public function deactivate(Request $request, Property $property): PropertyResource
    {
        return $this->setActive($request, $property, false);
    }

    private function setActive(Request $request, Property $property, bool $active): PropertyResource
    {
        abort_unless($request->user()->hasRole('admin', 'supervisor'), 403);

        $property->update(['is_active' => $active]);

        return new PropertyResource($property->fresh());
    }
}
