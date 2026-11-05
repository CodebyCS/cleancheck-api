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
use App\Modules\Reservations\Enums\IcalErrorCode;
use App\Modules\Reservations\Exceptions\IcalFetchException;
use App\Modules\Reservations\Exceptions\InvalidIcalException;
use App\Modules\Reservations\Http\Resources\IcalImportResource;
use App\Modules\Reservations\Services\IcalSyncService;
use App\Modules\Reservations\Exceptions\SyncInProgressException;

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

        public function sync(Request $request, BookingSource $bookingSource, IcalSyncService $syncService): JsonResponse
    {
        abort_unless($request->user()->hasRole('admin', 'supervisor'), 403);

        if ($bookingSource->type !== 'url' || ! $bookingSource->is_active) {
            return $this->error(
                IcalErrorCode::SourceNotSyncable,
                'Só fontes URL ativas podem ser sincronizadas.',
            );
        }

        try {
            $importResult = $syncService->sync($bookingSource);
        } catch (IcalFetchException|InvalidIcalException $e) {
            return $this->error($e->errorCode, $e->getMessage());
        } catch (SyncInProgressException $e) {
            return $this->error($e->errorCode, $e->getMessage(), 409);
        }

        return (new IcalImportResource($importResult, $bookingSource->property_id, $bookingSource->channel))
            ->response();
    }

    private function error(IcalErrorCode $code, string $message, int $status = 422): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code->value,
                'message' => $message,
            ],
        ], $status);
    }
}