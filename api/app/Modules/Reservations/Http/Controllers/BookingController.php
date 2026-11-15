<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Properties\Models\Property;
use App\Modules\Reservations\Events\BookingUpdated;
use App\Modules\Reservations\Http\Requests\UpdateBookingScheduleRequest;
use App\Modules\Reservations\Http\Resources\BookingResource;
use App\Modules\Reservations\Models\Booking;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class BookingController extends Controller
{
    public function index(Property $property): AnonymousResourceCollection
    {
        $bookings = Booking::with('bookingSource')
            ->whereHas('bookingSource', fn ($query) => $query->where('property_id', $property->id))
            ->orderBy('source_check_in_at')
            ->get();

        return BookingResource::collection($bookings);
    }

    public function updateSchedule(UpdateBookingScheduleRequest $request, Booking $booking): BookingResource
    {
        $booking->update([
            'overridden_check_in_at' => $request->validated('check_in'),
            'overridden_check_out_at' => $request->validated('check_out'),
        ]);

        // Tasks are rescheduled only when the effective dates may have changed.
        if ($booking->wasChanged()) {
            BookingUpdated::dispatch($booking->id);
        }

        return new BookingResource($booking->load('bookingSource'));
    }
}