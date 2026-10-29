<?php

namespace App\Modules\Tasks\Infrastructure;

use App\Modules\Reservations\Models\Booking;
use App\Modules\Tasks\Contracts\BookingDataProvider;
use App\Modules\Tasks\Support\BookingSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

        // The property is read by id so the Tasks module does not depend on the Properties models.
        $propertyActive = $propertyId !== null && (bool) DB::table('properties')->where('id', $propertyId)->value('is_active');

        $checkIn  = $booking->overridden_check_in_at ?? $booking->source_check_in_at;
        $checkOut = $booking->overridden_check_out_at ?? $booking->source_check_out_at;

        return new BookingSnapshot(
            bookingId: $booking->id,
            propertyId: $propertyId,
            checkIn: $checkIn ? Carbon::parse($checkIn) : null,
            checkOut: $checkOut ? Carbon::parse($checkOut) : null,
            nextCheckIn: ($propertyId && $checkOut) ? $this->findNextCheckIn($booking->id, $propertyId, Carbon::parse($checkOut)) : null,
            propertyActive: $propertyActive,
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