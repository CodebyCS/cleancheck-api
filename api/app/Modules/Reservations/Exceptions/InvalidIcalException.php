<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Exceptions;

use App\Modules\Reservations\Enums\IcalErrorCode;

final class InvalidIcalException extends \RuntimeException
{
    public function __construct(
        public readonly IcalErrorCode $errorCode,
        string $message
    ){
        parent::__construct($message);
    }
}
