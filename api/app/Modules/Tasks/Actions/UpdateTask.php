<?php

namespace App\Modules\Tasks\Actions;

use App\Models\Task;
use App\Modules\Tasks\Contracts\BookingDataProvider;
use App\Modules\Tasks\Services\CleaningSchedule;
use App\Modules\Tasks\Validators\TaskGenerationValidator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class UpdateTask
{
    private const PROTECTED_STATUSES = ['in_progress', 'completed'];

    private const EDITABLE_FIELDS = ['service_provider_id', 'agreed_amount', 'notes'];

    public function __construct(
        private BookingDataProvider $bookings,
        private CleaningSchedule $cleaningSchedule,
        private TaskGenerationValidator $validator,
    ) {}

    public function forBooking(int $bookingId): void
    {
        $data = $this->bookings->find($bookingId);

        if (!$data) {
            Log::warning('Update de task ignorado: reserva não encontrada.', ['booking_id' => $bookingId]);
            return;
        }

        $tasks = Task::where('booking_id', $bookingId)->get();

        foreach ($tasks as $task) {
            if (in_array($task->status, self::PROTECTED_STATUSES, true)) {
                Log::warning('Reserva alterada, mas a task já está em andamento ou concluída.', [
                    'task_id'    => $task->id,
                    'booking_id' => $bookingId,
                    'status'     => $task->status,
                ]);
                continue;
            }

            try {
                if ($task->type === 'checkout_cleaning') {
                    $this->validator->validateCheckoutCleaning($data);
                    $schedule = $this->cleaningSchedule->calculate($task->type, $data->checkOut, $data->nextCheckIn);

                    $task->update([
                        'scheduled_start_at' => $schedule['scheduled_start_at'],
                        'scheduled_end_at'   => $schedule['scheduled_end_at'],
                    ]);
                } elseif ($task->type === 'checkin_inspection') {
                    $this->validator->validateInspection($data);
                    $schedule = $this->cleaningSchedule->calculate($task->type, $data->checkIn);

                    $task->update(['scheduled_end_at' => $schedule['scheduled_end_at']]);
                }
            } catch (ValidationException $e) {
                Log::warning('Update de task ignorado: dados da reserva inválidos.', [
                    'task_id'    => $task->id,
                    'booking_id' => $bookingId,
                    'errors'     => $e->errors(),
                ]);
            }
        }
    }

    public function manual(int $taskId, array $data, int $userId): Task
    {
        return DB::transaction(function () use ($taskId, $data, $userId): Task {
            $task = Task::whereKey($taskId)->lockForUpdate()->firstOrFail();
            $changes = Arr::only($data, self::EDITABLE_FIELDS);
            $assignmentChanged = false;

            if (array_key_exists('service_provider_id', $changes)) {
                $providerId = $changes['service_provider_id'];
                if ($providerId === null) {
                    throw ValidationException::withMessages([
                        'service_provider_id' => 'A remoção da atribuição não é permitida.',
                    ]);
                }
                $activeProvider = DB::table('service_providers')
                    ->join('users', 'users.id', '=', 'service_providers.user_id')
                    ->where('service_providers.id', $providerId)
                    ->where('users.is_active', true)
                    ->lockForUpdate()
                    ->exists();
                if (! $activeProvider) {
                    throw ValidationException::withMessages([
                        'service_provider_id' => 'O prestador não existe ou está inativo.',
                    ]);
                }
                if ((string) $providerId === (string) $task->service_provider_id) {
                    unset($changes['service_provider_id']);
                } elseif ($task->service_provider_id === null && $task->status !== 'pending') {
                    throw ValidationException::withMessages([
                        'service_provider_id' => 'A primeira atribuição só é permitida para tarefas pendentes.',
                    ]);
                } else {
                    $assignmentChanged = true;
                }
            }

            if ($changes === []) {
                return $task;
            }

            if ($task->status === 'completed') {
                throw ValidationException::withMessages([
                    'status' => 'Não é possível editar uma tarefa concluída.',
                ]);
            }

            $previousProviderId = $task->service_provider_id;
            $task->update($changes);

            if ($assignmentChanged) {
                $task->assignmentHistory()->create([
                    'previous_service_provider_id' => $previousProviderId,
                    'new_service_provider_id' => $task->service_provider_id,
                    'user_id' => $userId,
                    'created_at' => now(),
                ]);
            }

            return $task;
        });
    }
}
