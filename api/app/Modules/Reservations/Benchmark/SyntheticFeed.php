<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Benchmark;

use Carbon\CarbonImmutable;

/**
 * Airbnb-style iCal feeds generated in memory for the sync benchmark.
 * Everything is deterministic: the same numbers always give the same feed.
 */
final class SyntheticFeed
{
    // Reserved ".test" domain: it never exists on the internet.
    public const HOST = 'ical-bench.test';

    public const FAILURE_KINDS = ['http_500', 'timeout', 'invalid'];

    public static function url(int $number): string
    {
        return 'https://'.self::HOST."/calendars/{$number}.ics?s=fict";
    }

    /**
     * Reservations of one feed: 3-night stays with a free day between them, all in the future
     * (only future check-outs can be cancelled). With $changePercent, some are removed and
     * others have their check-out moved one day later.
     *
     * @return array<string, array{0: string, 1: string}> UID => [check-in, check-out] as Ymd
     */
    public static function reservations(int $number, int $perFeed, int $changePercent = 0): array
    {
        $reservations = [];
        $firstCheckIn = CarbonImmutable::today()->addDays(2);

        for ($index = 0; $index < $perFeed; $index++) {
            $checkIn = $firstCheckIn->addDays($index * 4);
            $checkOut = $checkIn->addDays(3);
            $roll = self::roll("{$number}:{$index}");

            if ($roll < $changePercent) {
                // Half of the changed reservations are cancelled by the guest, the other half extended.
                if ($roll % 2 === 0) {
                    continue;
                }

                $checkOut = $checkOut->addDay();
            }

            $uid = sprintf('bench-%05d-%03d@%s', $number, $index, self::HOST);
            $reservations[$uid] = [$checkIn->format('Ymd'), $checkOut->format('Ymd')];
        }

        return $reservations;
    }

    /** Whether this feed fails in this pass, and how. */
    public static function failure(int $number, int $pass, int $failPercent): ?string
    {
        if (self::roll("{$number}:fail:{$pass}") >= $failPercent) {
            return null;
        }

        return self::FAILURE_KINDS[$number % count(self::FAILURE_KINDS)];
    }

    /** @param array<string, array{0: string, 1: string}> $reservations */
    public static function ics(array $reservations): string
    {
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//CleanCheck//Benchmark//PT', 'CALSCALE:GREGORIAN'];

        foreach ($reservations as $uid => [$checkIn, $checkOut]) {
            array_push(
                $lines,
                'BEGIN:VEVENT',
                "DTSTART;VALUE=DATE:{$checkIn}",
                "DTEND;VALUE=DATE:{$checkOut}",
                "UID:{$uid}",
                'SUMMARY:Reserved',
                'END:VEVENT',
            );
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines)."\r\n";
    }

    // A number from 0 to 99 that depends only on the key: no randomness between runs.
    private static function roll(string $key): int
    {
        return crc32($key) % 100;
    }
}