<?php

namespace App\Actions;

use App\Models\Task;

class CreateCheckoutCleaningTask
{
    /**
     * Create a new class instance.
     */
        // Class for create Task through a booking
        
    public function execute(
        int $bookingId,
        int $propertyId,
        \DateTimeInterface $checkoutAt,
        ?\DateTimeInterface $nextCheckinAt
    ): Task {
        // Schedule cleaning from checkout day at 11:30.
        $scheduledStartAt = \Illuminate\Support\Carbon::parse($checkoutAt)->setTime(11,30);

        // Use the next check-in as the end of the cleaning window.
        $scheduledEndAt = $nextCheckinAt ? \Illuminate\Support\Carbon::parse($nextCheckinAt) : null;

        return Task::create([
            'booking_id' => $bookingId,
            'property_id' => $propertyId,
            'type' => 'checkout_cleaning',
            'status' => 'pending',
            'scheduled_start_at' => $scheduledStartAt,
            'scheduled_end_at' => $scheduledEndAt,
        ]);
    }
}
