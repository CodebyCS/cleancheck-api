<?php

namespace App\Modules\Tasks\Actions;

use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CancelTask
{
    private const PROTECTED_STATUSES = ['in_progress', 'completed'];

    /** Cancel eligible tasks when a reservation is cancelled or removed. */
    public function forBooking(int $bookingId): void
    {
        DB::transaction(function () use ($bookingId): void {
            $tasks = Task::query()
                ->where('booking_id', $bookingId)
                ->lockForUpdate()
                ->get();

            foreach ($tasks as $task) {
                if (in_array($task->status, self::PROTECTED_STATUSES, true)) {
                    Log::warning('Reserva cancelada, mas a task já está em andamento ou concluída.', [
                        'task_id' => $task->id,
                        'booking_id' => $bookingId,
                        'type' => $task->type,
                        'status' => $task->status,
                    ]);

                    continue;
                }

                $this->cancelAndSoftDelete($task);
            }
        });
    }

    /**
     * Cancel the open tasks of a property that was deactivated.
     *
     * Only pending tasks whose window has not ended are cancelled. Overdue tasks
     * are left for the team to decide, and in-progress or completed ones are kept.
     */
    public function forProperty(int $propertyId): void
    {
        DB::transaction(function () use ($propertyId): void {
            // The property may have been reactivated before this ran (queued listener).
            if (DB::table('properties')->where('id', $propertyId)->value('is_active')) {
                return;
            }

            $tasks = Task::query()
                ->where('property_id', $propertyId)
                ->where('status', 'pending')
                ->where(fn ($query) => $query
                    ->whereNull('scheduled_end_at')
                    ->orWhere('scheduled_end_at', '>', now()))
                ->lockForUpdate()
                ->get();

            foreach ($tasks as $task) {
                $this->cancelAndSoftDelete($task);
            }
        });
    }

    /** Manually cancel a task on behalf of the team. */
    public function manual(int $taskId): void
    {
        DB::transaction(function () use ($taskId): void {
            $task = Task::withTrashed()
                ->lockForUpdate()
                ->findOrFail($taskId);

            // A repeated cancellation is a success with no changes.
            if ($task->trashed() && $task->status === 'cancelled') {
                return;
            }

            // Do not turn an administrative removal into a cancellation.
            if ($task->trashed()) {
                throw ValidationException::withMessages([
                    'status' => 'A tarefa já foi apagada por outra operação e não pode ser cancelada.',
                ]);
            }

            if (in_array($task->status, self::PROTECTED_STATUSES, true)) {
                throw ValidationException::withMessages([
                    'status' => 'Não é possível cancelar uma tarefa em andamento ou concluída.',
                ]);
            }

            $this->cancelAndSoftDelete($task);
        });
    }

    private function cancelAndSoftDelete(Task $task): void
    {
        if ($task->status !== 'cancelled') {
            $task->status = 'cancelled';
            $task->save();
        }

        if (! $task->trashed()) {
            $task->delete();
        }
    }
}