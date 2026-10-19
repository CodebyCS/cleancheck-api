<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Reservations\Exceptions\InvalidIcalException;
use App\Modules\Reservations\Http\Requests\IcalReadRequest;
use App\Modules\Reservations\Http\Resources\IcalReadResource;
use App\Modules\Reservations\Ical\Channels\AirbnbAdapter;
use Illuminate\Http\JsonResponse;

final class IcalReadController extends Controller
{
    public function __construct(
        private readonly AirbnbAdapter $adapter,
    ) {
    }

    public function __invoke(IcalReadRequest $request): JsonResponse
    {
        $propertyId = $request->integer('property_id');

        try {
            $result = $this->adapter->parseFeed($request->file('file')->get(), $propertyId);
        } catch (InvalidIcalException $e) {
            return response()->json([
                'error' => [
                    'code' => $e->errorCode->value,
                    'message' => $e->getMessage(),
                ],
            ], 422);
        }

        return (new IcalReadResource($result, $propertyId, $this->adapter->channel()))
            ->response();
    }
}