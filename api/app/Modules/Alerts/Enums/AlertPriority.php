<?php

declare(strict_types=1);

namespace App\Modules\Alerts\Enums;

enum AlertPriority: string
{
    case Medium = 'medium';
    case High = 'high';
}