<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Models;

use App\Modules\Reservations\Enums\ReservationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'booking_source_id',
    'external_uid',
    'source_check_in_at',
    'source_check_out_at',
    'overridden_check_in_at',
    'overridden_check_out_at',
    'guest_count',
    'status',
    'source_updated_at',
])]
final class Booking extends Model
{
    protected function casts(): array
    {
        return [
            'source_check_in_at' => 'immutable_datetime',
            'source_check_out_at' => 'immutable_datetime',
            'overridden_check_in_at' => 'immutable_datetime',
            'overridden_check_out_at' => 'immutable_datetime',
            'source_updated_at' => 'immutable_datetime',
            'guest_count' => 'integer',
            'status' => ReservationStatus::class,
        ];
    }

    public function bookingSource(): BelongsTo
    {
        return $this->belongsTo(BookingSource::class);
    }
}