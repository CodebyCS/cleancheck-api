<?php

declare(strict_types=1);

use App\Modules\Reservations\Benchmark\SyncBenchmark;
use App\Modules\Reservations\Benchmark\SyntheticFeed;
use App\Modules\Reservations\Events\BookingCancelled;
use App\Modules\Reservations\Events\BookingImported;
use App\Modules\Reservations\Events\BookingUpdated;
use App\Modules\Reservations\Models\SyncRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    Event::fake([BookingImported::class, BookingUpdated::class, BookingCancelled::class]);
});

it('generates the same Airbnb-style feed for the same numbers', function () {
    $feed = SyntheticFeed::reservations(7, 5, changePercent: 40);

    expect($feed)->toBe(SyntheticFeed::reservations(7, 5, changePercent: 40))
        ->and(SyntheticFeed::ics($feed))
        ->toContain('DTSTART;VALUE=DATE:', 'SUMMARY:Reserved')
        ->and(SyntheticFeed::url(7))->toEndWith('?s=fict');
});

it('converges without lost bookings, duplicates or hidden failures', function () {
    // 12 sources, 6 reservations each: sources 2 and 11 fail in pass 1; 1, 4, 7 and 11 in pass 2.
    $report = (new SyncBenchmark(sourceCount: 12, perFeed: 6, failPercent: 40, changePercent: 50))
        ->run(fn () => null);

    [$initial, $repeat, $change] = $report['passes'];

    foreach ($report['passes'] as $pass) {
        expect($pass['checks'])->toBe([
            'mismatched_sources' => 0,
            'duplicate_bookings' => 0,
            'stuck_runs' => 0,
            'unrecorded_failures' => 0,
        ]);
    }

    // Every failure must be an expected one (download or calendar), never an internal error.
    expect(collect($report['rows'])->where('status', 'UNEXPECTED'))->toBeEmpty()
        ->and(collect($report['rows'])->where('failure_kind', 'timeout')->pluck('status')->unique()->all())->toBe(['FETCH_FAILED']);

    expect($initial['outcomes']['ok'])->toBe(10)
        ->and($initial['created'])->toBe(60)
        // Repetition: only source 2, which failed before, creates its 6 bookings now.
        ->and($repeat['outcomes']['ok'])->toBe(8)
        ->and($repeat['created'])->toBe(6)
        ->and($repeat['updated'] + $repeat['cancelled'])->toBe(0)
        ->and($change['outcomes'])->toBe(['ok' => 12])
        ->and($change['updated'])->toBeGreaterThan(0)
        ->and($change['cancelled'])->toBeGreaterThan(0)
        ->and(SyncRun::where('status', 'failed')->count())->toBe(6)
        ->and($report['rows'])->toHaveCount(36);
});

it('refuses to run outside a benchmark database', function () {
    $this->artisan('reservations:benchmark', ['--sources' => 1])
        ->expectsOutputToContain('use uma base só para benchmark')
        ->assertFailed();
});