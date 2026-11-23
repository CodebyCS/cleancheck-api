<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'booking_source_id',
    'started_at',
    'finished_at',
    'status',
    'created_count',
    'updated_count',
    'cancelled_count',
    'error_message',
])]
final class SyncRun extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    public function bookingSource(): BelongsTo
    {
        return $this->belongsTo(BookingSource::class);
    }
}