<?php

namespace App\Modules\Tasks\Services;

use DateTimeInterface;
use Illuminate\Support\Carbon;

class CleaningSchedule
{
    public function calculate(
        string $type,
        DateTimeInterface $referenceDate,
        ?DateTimeInterface $nextCheckIn = null
    ): array {
        $date = Carbon::instance($referenceDate);

        switch ($type) {
            case 'checkout_cleaning':
                return [
                    'scheduled_start_at' => $date->copy()->setTime(11, 30),
                    'scheduled_end_at'   => $nextCheckIn ? Carbon::instance($nextCheckIn)->setTime(14, 30) : null,
                ];

            case 'checkin_inspection':
                return [
                    'scheduled_start_at' => Carbon::now(),
                    'scheduled_end_at' => $date->copy()->setTime(14,30),
                ];

            default:
                throw new \InvalidArgumentException("Tipo de tarefa não compatível: {$type}");
        }
    }
}