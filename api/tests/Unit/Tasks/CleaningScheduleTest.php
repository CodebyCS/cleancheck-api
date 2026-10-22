<?php

namespace Tests\Unit\Tasks;

use App\Modules\Tasks\Services\CleaningSchedule;
use PHPUnit\Framework\TestCase;

class CleaningScheduleTest extends TestCase
{
    private CleaningSchedule $schedule;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schedule = new CleaningSchedule();
    }

    public function test_checkout_cleaning_starts_at_1130(): void
    {
        $result = $this->schedule->calculate(
            'checkout_cleaning',
            new \DateTimeImmutable('2026-10-20 10:00:00')
        );

        $this->assertSame(
            '2026-10-20 11:30:00',
            $result['scheduled_start_at']->format('Y-m-d H:i:s')
        );

        $this->assertNull($result['scheduled_end_at']);
    }

    public function test_checkout_cleaning_uses_next_checkin_as_end_date(): void
    {
        $result = $this->schedule->calculate(
            'checkout_cleaning',
            new \DateTimeImmutable('2026-10-20 10:00:00'),
            new \DateTimeImmutable('2026-10-21 09:00:00')
        );

        $this->assertSame(
            '2026-10-20 11:30:00',
            $result['scheduled_start_at']->format('Y-m-d H:i:s')
        );

        $this->assertSame(
            '2026-10-21 14:30:00',
            $result['scheduled_end_at']->format('Y-m-d H:i:s')
        );
    }

    public function test_inspection_uses_current_time_as_start_and_checkin_day_at_1430_as_end(): void
    {
        $result = $this->schedule->calculate(
            'checkin_inspection',
            new \DateTimeImmutable('2026-10-20 15:00:00')
        );

        $this->assertInstanceOf(
            \DateTimeInterface::class,
            $result['scheduled_start_at']
        );

        $this->assertSame(
            '2026-10-20 14:30:00',
            $result['scheduled_end_at']->format('Y-m-d H:i:s')
        );
    }

    public function test_unknown_task_type_throws_exception(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->schedule->calculate(
            'unknown_type',
            new \DateTimeImmutable('2026-10-20 10:00:00')
        );
    }
}