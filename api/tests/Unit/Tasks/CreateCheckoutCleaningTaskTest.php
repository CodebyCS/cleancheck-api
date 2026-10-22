<?php

namespace Tests\Unit;

use App\Modules\Tasks\Services\CleaningSchedule;
use PHPUnit\Framework\TestCase;

class CreateCheckoutCleaningTaskTest extends TestCase
{
    public function test_checkout_cleaning_starts_at_1130(): void
    {
        $schedule = new CleaningSchedule();

        $result = $schedule->calculate(
            'checkout_cleaning',
            new \DateTimeImmutable('2026-10-20 10:00:00')
        );

        $this->assertSame(
            '2026-10-20 11:30:00',
            $result['scheduled_start_at']->format('Y-m-d H:i:s')
        );

        $this->assertNull($result['scheduled_end_at']);
    }

    public function test_checkin_inspection_is_scheduled_at_1430(): void
    {
        $schedule = new CleaningSchedule();

        $result = $schedule->calculate(
            'checkin_inspection',
            new \DateTimeImmutable('2026-10-20 15:00:00')
        );

        $this->assertSame(
            '2026-10-20 14:30:00',
            $result['scheduled_start_at']->format('Y-m-d H:i:s')
        );

        $this->assertNull($result['scheduled_end_at']);
    }

    public function test_unknown_task_type_is_rejected(): void
    {
        $schedule = new CleaningSchedule();

        $this->expectException(\InvalidArgumentException::class);

        $schedule->calculate(
            'unknown_type',
            new \DateTimeImmutable('2026-10-20 10:00:00')
        );
    }
}