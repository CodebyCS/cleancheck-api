<?php

declare(strict_types=1);

namespace App\Modules\Reservations\DTO;

use App\Modules\Reservations\Enums\IcalWarningCode;

final readonly class ParseWarning
{
    public function __construct(
        public ?string $uid,
        public IcalWarningCode $code,
        public string $message,
    ) {
    }
}