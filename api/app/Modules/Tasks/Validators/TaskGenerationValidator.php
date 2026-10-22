<?php

namespace App\Modules\Tasks\Validators;

use App\Modules\Tasks\Support\BookingSnapshot;
use Illuminate\Validation\ValidationException;

class TaskGenerationValidator
{
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

        if ($data->checkIn && $data->checkIn->copy()->setTime(14, 30)->isPast()) {
            $errors['check_in'] = 'O prazo da inspeção (14h30 do dia do check-in) já passou.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}