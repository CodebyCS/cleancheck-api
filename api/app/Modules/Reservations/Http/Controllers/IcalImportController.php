<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Reservations\Exceptions\InvalidIcalException;
use App\Modules\Reservations\Http\Requests\IcalUploadRequest;
use App\Modules\Reservations\Http\Resources\IcalImportResource;
use App\Modules\Reservations\Ical\Channels\AirbnbAdapter;
use App\Modules\Reservations\Services\BookingImportService;
use Illuminate\Http\JsonResponse;

final class IcalImportController extends Controller
{
    public function __construct(
        private readonly AirbnbAdapter $adapter,
        private readonly BookingImportService $importService,
    ) {
    }

    public function __invoke(IcalUploadRequest $request): JsonResponse
    {
        $propertyId = $request->integer('property_id');
        $channel = $this->adapter->channel();

        try {
            $parseResult = $this->adapter->parseFeed($request->file('file')->get(), $propertyId);
        } catch (InvalidIcalException $e) {
            return response()->json([
                'error' => [
                    'code' => $e->errorCode->value,
                    'message' => $e->getMessage(),
                ],
            ], 422);
        }

        $importResult = $this->importService->import($propertyId, $channel, $parseResult);

        return (new IcalImportResource($importResult, $propertyId, $channel))->response();
    }
}