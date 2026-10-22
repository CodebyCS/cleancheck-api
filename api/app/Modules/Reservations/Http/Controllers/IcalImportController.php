<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Reservations\Enums\IcalErrorCode;
use App\Modules\Reservations\Exceptions\InvalidIcalException;
use App\Modules\Reservations\Http\Requests\IcalUploadRequest;
use App\Modules\Reservations\Http\Resources\IcalImportResource;
use App\Modules\Reservations\Ical\Channels\AirbnbAdapter;
use App\Modules\Reservations\Services\BookingImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

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
            return $this->error($e->errorCode, $e->getMessage());
        }

        try {
            $importResult = $this->importService->import($propertyId, $channel, $parseResult);
        } catch (ValidationException) {
            return $this->error(
                IcalErrorCode::TaskGenerationFailed,
                'Não foi possível gerar as tarefas de limpeza. Nenhuma reserva foi importada.',
            );
        }

        return (new IcalImportResource($importResult, $propertyId, $channel))->response();
    }

    private function error(IcalErrorCode $code, string $message): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code->value,
                'message' => $message,
            ],
        ], 422);
    }
}