<?php

declare(strict_types=1);

namespace App\Modules\Tasks\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** The task was cancelled. providerId is who had it at that moment (null if unassigned). */
final class TaskCancelled implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $taskId,
        public readonly ?int $providerId,
    ) {
    }
}