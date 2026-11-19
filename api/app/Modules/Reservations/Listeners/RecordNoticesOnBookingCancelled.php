<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Listeners;

use App\Modules\Reservations\Enums\BookingNoticeType;
use App\Modules\Reservations\Events\BookingCancelled;
use App\Modules\Reservations\Services\RecordBookingNotices;
use Illuminate\Contracts\Queue\ShouldQueue;

final class RecordNoticesOnBookingCancelled implements ShouldQueue
{
    public bool $afterCommit = true;

    public int $tries = 3;

    public array $backoff = [10, 60];

    public function __construct(private readonly RecordBookingNotices $notices)
    {
    }

    public function handle(BookingCancelled $event): void
    {
        $this->notices->record($event->bookingId, BookingNoticeType::BookingCancelled);
    }
}