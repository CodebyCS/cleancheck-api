<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Models;

use App\Models\Task;
use App\Models\User;
use App\Modules\Reservations\Enums\BookingNoticeType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'booking_id',
    'task_id',
    'type',
    'check_in_on',
    'check_out_on',
    'read_at',
])]
final class BookingNotice extends Model
{
    protected function casts(): array
    {
        return [
            'type' => BookingNoticeType::class,
            'check_in_on' => 'immutable_date',
            'check_out_on' => 'immutable_date',
            'read_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function task(): BelongsTo
    {
        // A cancelled booking may already have its task removed.
        return $this->belongsTo(Task::class)->withTrashed();
    }
}