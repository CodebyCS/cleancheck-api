<?php

namespace App\Modules\Tasks\Actions;

use App\Models\Task;
use App\Modules\Tasks\Contracts\BookingDataProvider;
use App\Modules\Tasks\Services\CleaningSchedule;
use App\Modules\Tasks\Validators\TaskGenerationValidator;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;

class RestoreTask
{
    public function __construct(
        private BookingDataProvider $bookings,
        private CleaningSchedule $cleaningSchedule,
        private TaskGenerationValidator $validator,
    ) {}

    public function manual(int $taskId): Task
    {

        $task = Task::onlyTrashed()->findOrFail($taskId);

        $data = $this->bookings->find($task->booking_id)
            ?? throw ValidationException::withMessages([
                'booking_id' => 'A reserva desta tarefa já não existe, não é possível restaurar.',
            ]);

        if ($task->type === 'checkout_cleaning') {
            $this->validator->validateCheckoutCleaning($data);
            if ($data->checkOut->copy()->setTime(11, 30)->isPast()) {throw ValidationException::withMessages([
                'check_out' => 'A reserva já terminou, não é possível restaurar a limpeza.',
            ]);
        }
            $schedule = $this->cleaningSchedule->calculate($task->type, $data->checkOut, $data->nextCheckIn);
        } else {
            $this->validator->validateInspection($data);
            $schedule = $this->cleaningSchedule->calculate($task->type, $data->checkIn);
        }

        DB::transaction(function () use ($task, $schedule) {
        $task->restore();
        $task->update([
            'scheduled_start_at' => $schedule['scheduled_start_at'],
            'scheduled_end_at'   => $schedule['scheduled_end_at'],
        ]);
    });

        return $task;
    }
}