<?php

declare(strict_types=1);

namespace App\Modules\Alerts\Enums;

enum AlertResolution: string
{
    case TaskStarted = 'task_started';
    case TaskCompleted = 'task_completed';
    case TaskCancelled = 'task_cancelled';
    case Rescheduled = 'rescheduled';
    case Expired = 'expired';
    case Manual = 'manual';
}