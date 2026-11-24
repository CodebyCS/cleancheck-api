<?php

declare(strict_types=1);

namespace App\Modules\Alerts\Enums;

/** What the team should do next. */
enum AlertAction: string
{
    // The provider has not started: confirm they are on the way.
    case ContactProvider = 'contact_provider';

    // The guest arrives soon: send help or reassign the task.
    case ReassignOrHelp = 'reassign_or_help';
}