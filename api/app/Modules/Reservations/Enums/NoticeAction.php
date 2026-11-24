<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Enums;

/** Next step shown with a notice. */
enum NoticeAction: string
{
    // Pending task: the window may have moved.
    case CheckNewSchedule = 'check_new_schedule';

    // Started task: the booking changed, but the execution and the window were kept.
    case ContinueTask = 'continue_task';

    // Started task of a cancelled booking: the supervisor decides whether to finish it.
    case ContactSupervisor = 'contact_supervisor';

    // Staff: a started task of this booking was kept and may need a decision.
    case ReviewStartedTask = 'review_started_task';

    // New assignment: the task is now in the provider's list.
    case OpenTask = 'open_task';

    case None = 'none';
}