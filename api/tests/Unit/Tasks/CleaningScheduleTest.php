<?php

namespace Tests\Unit\Tasks;

use App\Modules\Tasks\Services\CleaningSchedule;
use PHPUnit\Framework\TestCase;
use Illuminate\Support\Carbon;

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

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_inspection_opens_now_and_closes_at_1430_of_checkin_day(): void
    {
        Carbon::setTestNow('2026-10-10 09:00:00');

        $result = $this->schedule->calculate(
            'checkin_inspection',
            new \DateTimeImmutable('2026-10-15 15:00:00')
        );

        $this->assertSame('2026-10-10 09:00:00', $result['scheduled_start_at']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-15 14:30:00', $result['scheduled_end_at']->format('Y-m-d H:i:s'));
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