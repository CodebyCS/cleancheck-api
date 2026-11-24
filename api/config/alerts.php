<?php

return [
    // Medium: a pending task that has not started this many minutes after its planned start.
    'late_start_after_minutes' => (int) env('ALERT_LATE_START_AFTER_MINUTES', 30),

    // High: an unfinished task this many minutes before the end of its window.
    // The window ends 30 min before the next check-in, so 30 here means 60 min before the guest arrives.
    'deadline_warning_minutes' => (int) env('ALERT_DEADLINE_WARNING_MINUTES', 30),

    // Older tasks are history, not something the team can still act on.
    'ignore_older_than_hours' => (int) env('ALERT_IGNORE_OLDER_THAN_HOURS', 24),
];