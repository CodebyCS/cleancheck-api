<?php

namespace App\Modules\Tasks\Services;

use App\Modules\Tasks\Support\PropertySchedule;
use DateTimeInterface;
use Illuminate\Support\Carbon;

class CleaningSchedule
{
    // Primary task window, based on the Airbnb/Booking standard (check-out 11:00, check-in 15:00).
    public const DEFAULT_START_TIME = '11:30';

    public const DEFAULT_END_TIME = '14:30';

    // When the property defines its own guest hours, the task keeps this gap
    // after the guest leaves and before the next guest arrives.
    public const MARGIN_MINUTES = 30;

    /**
     * Precedence:
     * - the day comes from the booking (local adjustment, otherwise the source value);
     * - the hour is 11:30 / 14:30, unless the property defines its guest hours
     *   (then check-out + 30 min / check-in - 30 min);
     * - the hour is applied in the property's timezone and returned in the input timezone.
     *
     * Booking dates come from iCal as calendar dates stored at 00:00 UTC, so the day is
     * read as-is (never converted), otherwise properties behind UTC would get the previous day.
     */
    public function calculate(
        string $type,
        DateTimeInterface $referenceDate,
        ?DateTimeInterface $nextCheckIn = null,
        ?PropertySchedule $property = null,
    ): array {
        switch ($type) {
            case 'checkout_cleaning':
                return [
                    'scheduled_start_at' => $this->startAt($referenceDate, $property),
                    'scheduled_end_at'   => $nextCheckIn ? $this->endAt($nextCheckIn, $property) : null,
                ];

            case 'checkin_inspection':
                return [
                    'scheduled_start_at' => Carbon::now(),
                    'scheduled_end_at' => $this->endAt($referenceDate, $property),
                ];

            default:
                throw new \InvalidArgumentException("Tipo de tarefa não compatível: {$type}");
        }
    }

    private function startAt(DateTimeInterface $date, ?PropertySchedule $property): Carbon
    {
        if ($property?->checkOutTime === null) {
            return $this->atTime($date, self::DEFAULT_START_TIME, $property);
        }

        return $this->atTime($date, $property->checkOutTime, $property)->addMinutes(self::MARGIN_MINUTES);
    }

    private function endAt(DateTimeInterface $date, ?PropertySchedule $property): Carbon
    {
        if ($property?->checkInTime === null) {
            return $this->atTime($date, self::DEFAULT_END_TIME, $property);
        }

        return $this->atTime($date, $property->checkInTime, $property)->subMinutes(self::MARGIN_MINUTES);
    }

    /**
     * Applies $time, in the property's timezone, to the calendar day of $date.
     */
    private function atTime(DateTimeInterface $date, string $time, ?PropertySchedule $property): Carbon
    {
        $original = Carbon::instance($date);

        if ($property === null) {
            return $original->copy()->setTimeFromTimeString($time);
        }

        return Carbon::parse($original->toDateString(), $property->timezone)
            ->setTimeFromTimeString($time)
            ->setTimezone($original->getTimezone());
    }
}
