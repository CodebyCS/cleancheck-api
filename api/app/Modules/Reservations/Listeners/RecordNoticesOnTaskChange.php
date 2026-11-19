<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Listeners;

use App\Modules\Reservations\Services\RecordTaskNotices;
use App\Modules\Tasks\Events\TaskAssigned;
use App\Modules\Tasks\Events\TaskCancelled;
use Illuminate\Contracts\Queue\ShouldQueue;

final class RecordNoticesOnTaskChange implements ShouldQueue
{
    public bool $afterCommit = true;

    public int $tries = 3;

    public array $backoff = [10, 60];

    public function __construct(private readonly RecordTaskNotices $notices)
    {
    }

    public function handleAssigned(TaskAssigned $event): void
    {
        $this->notices->assigned(
            $event->taskId,
            $event->previousProviderId,
            $event->newProviderId,
            $event->assignmentId,
        );
    }

    public function handleCancelled(TaskCancelled $event): void
    {
        $this->notices->cancelled($event->taskId, $event->providerId);
    }
}