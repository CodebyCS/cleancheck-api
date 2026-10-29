<?php

namespace App\Modules\Tasks\Actions;

use App\Models\Task;
use App\Modules\Tasks\Contracts\BookingDataProvider;
use App\Modules\Tasks\Services\CleaningSchedule;
use App\Modules\Tasks\Validators\TaskGenerationValidator;
use Illuminate\Validation\ValidationException;

class CreateManualTask
{
    public function __construct(
        private BookingDataProvider $bookings,
        private CleaningSchedule $cleaningSchedule,
        private TaskGenerationValidator $validator,
    ) {}

    public function execute(int $bookingId): Task
    {
        $data = $this->bookings->find($bookingId)
            ?? throw ValidationException::withMessages(['booking_id' => 'Reserva não encontrada.']);

        $this->validator->validateInspection($data);

        $type = 'checkin_inspection';

        $existing = Task::withTrashed()
            ->where('booking_id', $bookingId)
            ->where('type', $type)
            ->first();

        if ($existing) {
            throw ValidationException::withMessages([
                'booking_id' => $existing->trashed()
                    ? 'Já existe uma inspeção eliminada para esta reserva. Use a opção de restaurar.'
                    : 'Já existe uma tarefa de inspeção para esta reserva.',
            ]);
        }

        $this->validator->validatePropertyActive($data);

        $schedule = $this->cleaningSchedule->calculate($type, $data->checkIn);

        return Task::create([
            'booking_id'         => $bookingId,
            'property_id'        => $data->propertyId,
            'type'               => $type,
            'status'             => 'pending',
            'scheduled_start_at' => $schedule['scheduled_start_at'],
            'scheduled_end_at'   => $schedule['scheduled_end_at'],
        ]);
    }
}