<?php

namespace App\Modules\Tasks\Infrastructure;

use App\Modules\Properties\Models\Property;
use App\Modules\Reservations\Models\Booking;
use App\Modules\Tasks\Contracts\BookingDataProvider;
use App\Modules\Tasks\Support\BookingSnapshot;
use App\Modules\Tasks\Support\PropertySchedule;
use Illuminate\Support\Carbon;
use App\Modules\Reservations\Enums\ReservationStatus;

class EloquentBookingDataProvider implements BookingDataProvider
{
    public function find(int $bookingId): ?BookingSnapshot
    {
        $booking = Booking::with('bookingSource')
            ->where('status', ReservationStatus::Reserved)
            ->find($bookingId);

        if (!$booking) {
            return null;
        }

        $propertyId = $booking->bookingSource?->property_id;

        $checkIn  = $booking->overridden_check_in_at ?? $booking->source_check_in_at;
        $checkOut = $booking->overridden_check_out_at ?? $booking->source_check_out_at;

        return new BookingSnapshot(
            bookingId: $booking->id,
            propertyId: $propertyId,
            checkIn: $checkIn ? Carbon::parse($checkIn) : null,
            checkOut: $checkOut ? Carbon::parse($checkOut) : null,
            nextCheckIn: ($propertyId && $checkOut) ? $this->findNextCheckIn($booking->id, $propertyId, Carbon::parse($checkOut)) : null,
            propertySchedule: $propertyId ? $this->findPropertySchedule($propertyId) : null,
        );
    }

    private function findPropertySchedule(int $propertyId): ?PropertySchedule
    {
        $property = Property::find($propertyId);

        if (!$property) {
            return null;
        }

        return new PropertySchedule(
            checkOutTime: $property->default_check_out_time,
            checkInTime: $property->default_check_in_time,
            timezone: $property->timezone ?? Property::DEFAULT_TIMEZONE,
        );
    }

    private function findNextCheckIn(int $bookingId, int $propertyId, Carbon $checkOut): ?Carbon
    {
        $next = Booking::query()
            ->selectRaw('COALESCE(overridden_check_in_at, source_check_in_at) as effective_check_in')
            ->whereHas('bookingSource', fn ($q) => $q->where('property_id', $propertyId))
            ->where('id', '!=', $bookingId)
            ->where('status', ReservationStatus::Reserved)
            ->whereRaw('DATE(COALESCE(overridden_check_in_at, source_check_in_at)) >= ?', [$checkOut->toDateString()])
            ->orderBy('effective_check_in')
            ->first();

        return $next ? Carbon::parse($next->effective_check_in) : null;
    }
}