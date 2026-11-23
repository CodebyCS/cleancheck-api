<?php

namespace Database\Factories;

use App\Models\Task;
use App\Modules\Properties\Models\Property;
use App\Modules\Properties\Models\ServiceProvider;
use App\Modules\Reservations\Models\Booking;
use App\Modules\Reservations\Models\BookingSource;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    public function definition(): array
    {
        $propertyId = null;

        return [
            'property_id' => function (array $attributes = []) use (&$propertyId): int {
                if ($propertyId !== null) {
                    return $propertyId;
                }

                $existingPropertyId = Property::query()
                    ->where('is_active', true)
                    ->inRandomOrder()
                    ->value('id');

                $propertyId = $existingPropertyId
                    ? (int) $existingPropertyId
                    : $this->createProperty()->id;

                return $propertyId;
            },

            'booking_id' => function (array $attributes) use (&$propertyId): int {
                if (isset($attributes['property_id']) && is_numeric($attributes['property_id'])) {
                    $propertyId = (int) $attributes['property_id'];
                }

                $propertyId ??= $this->createProperty()->id;

                $bookingSource = BookingSource::query()->create([
                    'property_id' => $propertyId,
                    'channel' => 'test',
                    'type' => 'url',
                    'is_active' => true,
                ]);

                $booking = Booking::query()->create([
                    'booking_source_id' => $bookingSource->id,
                    'external_uid' => (string) Str::uuid(),
                    'source_check_in_at' => now()->addDays(2),
                    'source_check_out_at' => now()->addDays(3),
                    'status' => 'reserved',
                ]);

                return $booking->id;
            },

            'service_provider_id' => fn () => ServiceProvider::query()
                ->whereHas('user', fn ($query) => $query->where('is_active', true))
                ->inRandomOrder()
                ->value('id'),

            'type' => 'checkout_cleaning',
            'status' => 'pending',
            'scheduled_start_at' => now()->addDays(3)->setTime(11, 30),
            'scheduled_end_at' => now()->addDays(4)->setTime(14, 30),
            'started_at' => null,
            'completed_at' => null,
            'agreed_amount' => null,
            'notes' => null,
        ];
    }

    public function inProgress(): static
    {
        return $this->state(fn () => [
            'status' => 'in_progress',
            'started_at' => now()->subMinutes(20),
        ]);
    }

    public function checkinInspection(): static
    {
        return $this->state(fn () => [
            'type' => 'checkin_inspection',
            'scheduled_start_at' => now(),
            'scheduled_end_at' => now()->addDays(2)->setTime(14, 30),
        ]);
    }

    private function createProperty(): Property
    {
        return Property::query()->create([
            'name' => 'Task Factory ' . Str::uuid(),
            'address' => 'Rua de Demonstração, 1',
            'postal_code' => '1000-001',
            'city' => 'Lisboa',
            'is_active' => true,
        ]);
    }
}