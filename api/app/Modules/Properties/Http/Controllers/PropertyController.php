<?php

namespace App\Modules\Properties\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Properties\Http\Requests\StorePropertyRequest;
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

        return PropertyResource::collection(Property::orderBy('name')->get());
    }

    public function store(StorePropertyRequest $request): JsonResponse
    {
        $property = Property::create($request->validated());

        return (new PropertyResource($property))
            ->response()
            ->setStatusCode(201);
    }
}
