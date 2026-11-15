<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Http\Requests;

use App\Modules\Reservations\Enums\ReservationStatus;
use App\Modules\Reservations\Models\Booking;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class UpdateBookingScheduleRequest extends FormRequest
{
    // Roles are checked by the role:admin,supervisor route middleware.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'check_in' => ['present', 'nullable', 'date_format:Y-m-d'],
            'check_out' => ['present', 'nullable', 'date_format:Y-m-d'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                /** @var Booking $booking */
                $booking = $this->route('booking');

                if ($booking->status !== ReservationStatus::Reserved) {
                    $validator->errors()->add('booking', 'Só é possível ajustar reservas ativas.');

                    return;
                }

                // A missing adjustment falls back to the received date, as in the task schedule.
                $checkIn = $this->input('check_in') ?? $booking->source_check_in_at->format('Y-m-d');
                $checkOut = $this->input('check_out') ?? $booking->source_check_out_at->format('Y-m-d');

                if ($checkOut <= $checkIn) {
                    $validator->errors()->add('check_out', 'O check-out tem de ser depois do check-in.');
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'check_in.present' => 'Indique o check-in ajustado ou null para usar o recebido.',
            'check_out.present' => 'Indique o check-out ajustado ou null para usar o recebido.',
            'check_in.date_format' => 'O check-in tem de estar no formato AAAA-MM-DD.',
            'check_out.date_format' => 'O check-out tem de estar no formato AAAA-MM-DD.',
        ];
    }
}