<?php

return [
    // A url source without a successful sync for longer than this is shown as stale.
    // The scheduler runs every 30 minutes, so 120 means four missed cycles.
    'stale_after_minutes' => (int) env('ICAL_STALE_AFTER_MINUTES', 120),
];