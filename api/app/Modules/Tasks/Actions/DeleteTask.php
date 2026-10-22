<?php

namespace App\Modules\Tasks\Actions;

use App\Models\Task;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class DeleteTask
{
    private const PROTECTED_STATUSES = ['in_progress', 'completed'];

    public function forBooking(int $bookingId): void
    {
        $tasks = Task::where('booking_id', $bookingId)->get();

        foreach ($tasks as $task) {
            if (in_array($task->status, self::PROTECTED_STATUSES, true)) {
                Log::warning('Reserva removida, mas a task já está em andamento ou concluída.', [
                    'task_id'    => $task->id,
                    'booking_id' => $bookingId,
                    'type'       => $task->type,
                    'status'     => $task->status,
                ]);

                continue;
            }

            $task->delete();
        }
    }

    public function manual(int $taskId): void
    {
        $task = Task::findOrFail($taskId);

        if (in_array($task->status, self::PROTECTED_STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => 'Não é possível apagar uma tarefa em andamento ou concluída.',
            ]);
        }

        $task->delete();
    }
}