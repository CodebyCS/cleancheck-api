<?php
declare(strict_types=1);

namespace App\Modules\Reservations\DTO;

use DateTimeImmutable;
use App\Modules\Reservations\Enums\Reservationstatus;

final readonly class ReservationEvent
{
    public function __construct(
        public string $externalUid,

        public int $propertyId,

        public string $channel,
        public DateTimeImmutable $checkIn,
        public DateTimeImmutable $checkOut,

        public Reservationstatus $status,

        public ?int $sequence = null,

        public ?string $reservationUrl = null,

        public ?string $rawSummary = null,
        public ?string $rawDescription = null,
    ){
    }

    public function nights(): int
    {
        return (int) $this->checkIn->diff($this->checkOut)->days;
    }


}
