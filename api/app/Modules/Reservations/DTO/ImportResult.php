<?php

declare(strict_types=1);

namespace App\Modules\Reservations\DTO;

use App\Modules\Reservations\Models\Booking;

final readonly class ImportResult
{
    /**
     * @param Booking[] $created
     * @param Booking[] $existing
     * @param ReservationEvent[] $ambiguous
     * @param ReservationEvent[] $skipped
     * @param ParseWarning[] $warnings
     */
    public function __construct(
        public array $created = [],
        public array $existing = [],
        public array $ambiguous = [],
        public array $skipped = [],
        public array $warnings = [],
    ) {
    }
}