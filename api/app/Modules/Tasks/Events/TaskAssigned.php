<?php

declare(strict_types=1);

namespace App\Modules\Tasks\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** A task got a new provider. assignmentId is the task_assignment_history row (origin event). */
final class TaskAssigned implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $taskId,
        public readonly ?int $previousProviderId,
        public readonly int $newProviderId,
        public readonly int $assignmentId,
    ) {
    }
}