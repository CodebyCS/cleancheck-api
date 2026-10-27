<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Properties\Models\Property;
use App\Modules\Reservations\Http\Requests\StoreBookingSourceRequest;
use App\Modules\Reservations\Http\Resources\BookingSourceResource;
use App\Modules\Reservations\Models\BookingSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class BookingSourceController extends Controller
{
    public function index(Request $request, Property $property): AnonymousResourceCollection
    {
        abort_unless($request->user()->hasRole('admin', 'supervisor'), 403);

        return BookingSourceResource::collection(
            BookingSource::where('property_id', $property->id)->orderBy('id')->get(),
        );
    }

    public function store(StoreBookingSourceRequest $request, Property $property): JsonResponse
    {
        $source = BookingSource::create([
            'property_id' => $property->id,
            'channel' => 'airbnb',
            'type' => 'url',
            'url' => $request->validated('url'),
            'is_active' => true,
        ]);

        return (new BookingSourceResource($source))
            ->response()
            ->setStatusCode(201);
    }
}