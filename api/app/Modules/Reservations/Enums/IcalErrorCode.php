<?php
declare(strict_types=1);

namespace App\Modules\Reservations\Enums;

enum IcalErrorCode: string
{
    case NoFile = 'NO_FILE';
    case InvalidFileType = 'INVALID_FILE_TYPE';
    case EmptyFile = 'EMPTY_FILE';
    case InvalidIcal = 'INVALID_ICAL';
    case PropertyNotFound = 'PROPERTY_NOT_FOUND';
}
