<?php

namespace Tests\Unit\Tasks;

use App\Modules\Tasks\Services\CleaningSchedule;
use App\Modules\Tasks\Support\PropertySchedule;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class CleaningSchedulePropertyHoursTest extends TestCase
{
    private CleaningSchedule $schedule;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schedule = new CleaningSchedule();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function utc(string $value): Carbon
    {
        return Carbon::parse($value, 'UTC');
    }

    public function test_checkout_cleaning_uses_property_hours_in_property_timezone(): void
    {
        // Lisbon is UTC+1 in summer time, so 11:00 local is 10:00 UTC.
        $property = new PropertySchedule('11:00:00', '16:00:00', 'Europe/Lisbon');

        $result = $this->schedule->calculate(
            'checkout_cleaning',
            $this->utc('2026-10-20 09:00:00'),
            $this->utc('2026-10-21 09:00:00'),
            $property,
        );

        $this->assertSame('2026-10-20 10:00:00', $result['scheduled_start_at']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-21 15:00:00', $result['scheduled_end_at']->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $result['scheduled_start_at']->getTimezone()->getName());
    }

    public function test_winter_time_uses_the_right_offset(): void
    {
        // Lisbon is UTC+0 in winter time.
        $property = new PropertySchedule('11:00', '16:00', 'Europe/Lisbon');

        $result = $this->schedule->calculate(
            'checkout_cleaning',
            $this->utc('2026-12-20 09:00:00'),
            null,
            $property,
        );

        $this->assertSame('2026-12-20 11:00:00', $result['scheduled_start_at']->format('Y-m-d H:i:s'));
        $this->assertNull($result['scheduled_end_at']);
    }

    public function test_property_without_hours_falls_back_to_defaults_in_its_timezone(): void
    {
        // New York is UTC-5 in December, so 11:30 local is 16:30 UTC.
        $property = new PropertySchedule(null, null, 'America/New_York');

        $result = $this->schedule->calculate(
            'checkout_cleaning',
            $this->utc('2026-12-20 15:00:00'),
            $this->utc('2026-12-22 15:00:00'),
            $property,
        );

        $this->assertSame('2026-12-20 16:30:00', $result['scheduled_start_at']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-12-22 19:30:00', $result['scheduled_end_at']->format('Y-m-d H:i:s'));
    }

    public function test_the_day_is_taken_in_the_property_timezone(): void
    {
        // 23:30 UTC on 20/10 is already 21/10 in Lisbon.
        $property = new PropertySchedule('11:00', '16:00', 'Europe/Lisbon');

        $result = $this->schedule->calculate(
            'checkout_cleaning',
            $this->utc('2026-10-20 23:30:00'),
            null,
            $property,
        );

        $this->assertSame('2026-10-21 10:00:00', $result['scheduled_start_at']->format('Y-m-d H:i:s'));
    }

    public function test_inspection_closes_at_property_check_in_time(): void
    {
        Carbon::setTestNow('2026-10-10 09:00:00');
        $property = new PropertySchedule('11:00', '16:00', 'Europe/Lisbon');

        $result = $this->schedule->calculate(
            'checkin_inspection',
            $this->utc('2026-10-15 14:00:00'),
            null,
            $property,
        );

        $this->assertSame('2026-10-10 09:00:00', $result['scheduled_start_at']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-15 15:00:00', $result['scheduled_end_at']->format('Y-m-d H:i:s'));
    }
}
