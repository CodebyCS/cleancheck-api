<?php
declare(strict_types=1);

namespace api\app\Modules\Reservations\DTO;

use DateTimeImmutable;

final readonly class Reservationevent
{
    public function __construct(
        public string $externalUid,

        public int $propertyId,
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
