<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Exceptions;

use App\Modules\Reservations\Enums\IcalErrorCode;

final class SyncInProgressException extends \RuntimeException
{
    public readonly IcalErrorCode $errorCode;

    public function __construct()
    {
        parent::__construct('Esta fonte já está a ser sincronizada. Tente daqui a pouco.');

        $this->errorCode = IcalErrorCode::SyncInProgress;
    }
}