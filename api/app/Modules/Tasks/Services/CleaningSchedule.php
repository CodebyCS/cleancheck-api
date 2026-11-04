<?php

namespace App\Modules\Tasks\Services;

use App\Modules\Tasks\Support\PropertySchedule;
use DateTimeInterface;
use Illuminate\Support\Carbon;

class CleaningSchedule
{
    // System defaults, used when the property has no reference hours.
    public const DEFAULT_START_TIME = '11:30';

    public const DEFAULT_END_TIME = '14:30';

    /**
     * Precedence:
     * - the day comes from the booking (local adjustment, otherwise the source value);
     * - the hour comes from the property reference hours, otherwise the system default;
     * - both are resolved in the property's timezone and returned in the input timezone.
     */
    public function calculate(
        string $type,
        DateTimeInterface $referenceDate,
        ?DateTimeInterface $nextCheckIn = null,
        ?PropertySchedule $property = null,
    ): array {
        $startTime = $property?->checkOutTime ?? self::DEFAULT_START_TIME;
        $endTime = $property?->checkInTime ?? self::DEFAULT_END_TIME;

        switch ($type) {
            case 'checkout_cleaning':
                return [
                    'scheduled_start_at' => $this->atTime($referenceDate, $startTime, $property),
                    'scheduled_end_at'   => $nextCheckIn ? $this->atTime($nextCheckIn, $endTime, $property) : null,
                ];

            case 'checkin_inspection':
                return [
                    'scheduled_start_at' => Carbon::now(),
                    'scheduled_end_at' => $this->atTime($referenceDate, $endTime, $property),
                ];

            default:
                throw new \InvalidArgumentException("Tipo de tarefa não compatível: {$type}");
        }
    }

    /**
     * Keeps the local day of $date in the property's timezone and applies $time to it.
     */
    private function atTime(DateTimeInterface $date, string $time, ?PropertySchedule $property): Carbon
    {
        $original = Carbon::instance($date);

        if ($property === null) {
            return $original->copy()->setTimeFromTimeString($time);
        }

        return $original->copy()
            ->setTimezone($property->timezone)
            ->setTimeFromTimeString($time)
            ->setTimezone($original->getTimezone());
    }
}
