<?php

declare(strict_types=1);

namespace App\Modules\Reservations\DTO;

final readonly class ParseResult
{
    /**
     * @param ReservationEvent[] $events
     * @param ParseWarning[] $warnings
     */
    public function __construct(
        public array $events = [],
        public array $warnings = [],
    ) {
    }
}