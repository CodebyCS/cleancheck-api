<?php

namespace App\Modules\Tasks\Actions;

use App\Models\Task;
use App\Modules\Tasks\Contracts\BookingDataProvider;
use App\Modules\Tasks\Services\CleaningSchedule;
use App\Modules\Tasks\Validators\TaskGenerationValidator;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\UniqueConstraintViolationException;

class CreateCheckoutCleaningTask
{
    public function __construct(
        private BookingDataProvider $bookings,
        private CleaningSchedule $cleaningSchedule,
        private TaskGenerationValidator $validator,
        private CreateAssignedTask $createTask,
    ) {}

    public function execute(int $bookingId): ?Task
    {
        $data = $this->bookings->find($bookingId)
            ?? throw ValidationException::withMessages(['booking_id' => 'Reserva não encontrada.']);

        $this->validator->validateCheckoutCleaning($data);

        $type = 'checkout_cleaning';

        $existing = Task::withTrashed()
            ->where('booking_id', $bookingId)
            ->where('type', $type)
            ->first();

        if ($existing) {
            return $existing->trashed() ? null : $existing;
        }

        $this->validator->validatePropertyActive($data);

        $schedule = $this->cleaningSchedule->calculate($type, $data->checkOut, $data->nextCheckIn, $data->propertySchedule);

        try {
            return $this->createTask->execute([
                'booking_id'         => $bookingId,
                'property_id'        => $data->propertyId,
                'type'               => $type,
                'status'             => 'pending',
                'scheduled_start_at' => $schedule['scheduled_start_at'],
                'scheduled_end_at'   => $schedule['scheduled_end_at'],
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            $existing = Task::withTrashed()->where('booking_id', $bookingId)->where('type', $type)->first();

            if ($existing === null) {
                throw $exception;
            }

            return $existing->trashed() ? null : $existing;
        }
    }
}
