<?php

namespace App\Modules\Tasks\Validators;

use App\Modules\Tasks\Services\CleaningSchedule;
use App\Modules\Tasks\Support\BookingSnapshot;
use App\Modules\Properties\Models\Property;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TaskGenerationValidator
{
    public function __construct(
        private CleaningSchedule $cleaningSchedule = new CleaningSchedule(),
    ) {}

    public function validateCheckoutCleaning(BookingSnapshot $data): void
    {
        $errors = [];

        if (!$data->propertyId) {
            $errors['property_id'] = 'A reserva deve pertencer a uma propriedade.';
        }

        if (!$data->checkOut) {
            $errors['check_out'] = 'A reserva deve ter uma data de check-out válida.';
        }

        if ($data->checkIn && $data->checkOut && $data->checkIn >= $data->checkOut) {
            $errors['dates'] = 'O check-in deve ocorrer antes do check-out.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    public function validateInspection(BookingSnapshot $data): void
    {
        $errors = [];

        if (!$data->propertyId) {
            $errors['property_id'] = 'A reserva deve pertencer a uma propriedade.';
        }

        if (!$data->checkIn) {
            $errors['check_in'] = 'A reserva deve ter uma data de check-in válida.';
        }

        if ($data->checkIn) {
            // Same deadline as the schedule: 14:30, or the property check-in time minus 30 minutes.
            $deadline = $this->cleaningSchedule
                ->calculate('checkin_inspection', $data->checkIn, null, $data->propertySchedule)['scheduled_end_at'];

            if ($deadline->isPast()) {
                $localDeadline = $data->propertySchedule
                    ? $deadline->copy()->setTimezone($data->propertySchedule->timezone)
                    : $deadline;

                $errors['check_in'] = "O prazo da inspeção ({$localDeadline->format('H\\hi')} do dia do check-in) já passou.";
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** New tasks cannot be created (or restored) for an inactive property. */
    public function validatePropertyActive(BookingSnapshot $data): void
    {
        if ($data->propertyId !== null && ! $data->propertyActive) {
            throw ValidationException::withMessages([
                'property_id' => 'A propriedade está inativa.',
            ]);
        }
    }

    /** Resolve the current default inside the task creation transaction. */
    public function defaultProviderId(int $propertyId): ?int
    {
        $property = Property::whereKey($propertyId)->lockForUpdate()->first();

        if ($property === null || ! $property->is_active) {
            throw ValidationException::withMessages([
                'property_id' => 'A propriedade não existe ou está inativa.',
            ]);
        }

        $providerId = $property->responsible_service_provider_id;
        $activeProvider = $providerId !== null && DB::table('service_providers')
            ->join('users', 'users.id', '=', 'service_providers.user_id')
            ->where('service_providers.id', $providerId)
            ->where('users.is_active', true)
            ->lockForUpdate()
            ->exists();

        return $activeProvider ? (int) $providerId : null;
    }
}
