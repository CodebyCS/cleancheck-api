<?php
declare(strict_types=1);

namespace App\Modules\Reservations\Enums;

enum IcalWarningCode: string
{
     case MissingUid = 'MISSING_UID';

     case MissingDates = 'MISSING_DATES';

     case InvalidDateRange = 'INVALID_DATE_RANGE';
     
     case UnsupportedDateTime = 'UNSUPPORTED_DATE_TIME';

     case DuplicateUid = 'DUPLICATE_UID';
     
     case UnknownSummary = 'UNKNOWN_SUMMARY';
}
