<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Listeners;

use App\Modules\Reservations\Enums\BookingNoticeType;
use App\Modules\Reservations\Events\BookingUpdated;
use App\Modules\Reservations\Services\RecordBookingNotices;
use Illuminate\Contracts\Queue\ShouldQueue;

final class RecordNoticesOnBookingUpdated implements ShouldQueue
{
    public bool $afterCommit = true;

    public int $tries = 3;

    public array $backoff = [10, 60];

    public function __construct(private readonly RecordBookingNotices $notices)
    {
    }

    public function handle(BookingUpdated $event): void
    {
        $this->notices->record($event->bookingId, BookingNoticeType::BookingUpdated);
    }
}