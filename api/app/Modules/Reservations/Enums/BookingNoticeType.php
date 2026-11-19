<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Enums;

enum BookingNoticeType: string
{
    case BookingUpdated = 'booking_updated';
    case BookingCancelled = 'booking_cancelled';
    case TaskAssigned = 'task_assigned';
    case TaskUnassigned = 'task_unassigned';
    case TaskCancelled = 'task_cancelled';
}