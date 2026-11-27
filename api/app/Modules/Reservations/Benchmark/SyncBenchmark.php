<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Benchmark;

use App\Modules\Properties\Models\Property;
use App\Modules\Reservations\Enums\ReservationStatus;
use App\Modules\Reservations\Exceptions\IcalFetchException;
use App\Modules\Reservations\Exceptions\InvalidIcalException;
use App\Modules\Reservations\Exceptions\SyncInProgressException;
use App\Modules\Reservations\Ical\HostResolver;
use App\Modules\Reservations\Models\Booking;
use App\Modules\Reservations\Models\BookingSource;
use App\Modules\Reservations\Models\SyncRun;
use App\Modules\Reservations\Services\IcalSyncService;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Runs the real iCal sync over synthetic sources in three passes and measures each one:
 * 1. initial: first read, with failing sources;
 * 2. repeat: same feeds again, other sources failing (recovery and convergence);
 * 3. change: no failures, some reservations removed or moved.
 * Only the network is simulated; parsing, import, locks and database are the real ones.
 */
final class SyncBenchmark
{
    /** @var array<int, int> booking source id => synthetic number */
    private array $sources = [];

    /** @var array<int, array<string, array{0: string, 1: string}>> number => feed served in this pass */
    private array $feeds = [];

    /** @var array<int, string|null> number => failure kind in this pass */
    private array $failures = [];

    /** @var array<int, array<string, array{0: string, 1: string}>> number => last feed read successfully */
    private array $lastGood = [];

    /** @var list<array<string, int|float|string>> */
    private array $rows = [];

    private int $queries = 0;

    public function __construct(
        private readonly int $sourceCount,
        private readonly int $perFeed,
        private readonly int $failPercent,
        private readonly int $changePercent,
        private readonly int $minLatencyMs = 0,
        private readonly int $maxLatencyMs = 0,
    ) {
    }

    /**
     * @param callable(): void $onSourceDone called after each source, for a progress bar
     * @return array{passes: list<array<string, mixed>>, rows: list<array<string, int|float|string>>}
     */
    public function run(callable $onSourceDone): array
    {
        $this->fakeNetwork();
        DB::listen(function (): void {
            $this->queries++;
        });

        $this->createSources();
        $sync = app(IcalSyncService::class);

        $passes = [
            $this->runPass(1, 'initial', $this->failPercent, 0, $sync, $onSourceDone),
            $this->runPass(2, 'repeat', $this->failPercent, 0, $sync, $onSourceDone),
            $this->runPass(3, 'change', 0, $this->changePercent, $sync, $onSourceDone),
        ];

        return ['passes' => $passes, 'rows' => $this->rows];
    }

    private function fakeNetwork(): void
    {
        // The synthetic host "resolves" to a public address, so the real URL checks still run.
        // No connection is ever made: Http::fake answers first and any other request is refused.
        app()->instance(HostResolver::class, new class extends HostResolver
        {
            public function resolve(string $host): array
            {
                return $host === SyntheticFeed::HOST ? ['93.184.215.14'] : parent::resolve($host);
            }
        });

        Http::preventStrayRequests();
        Http::fake([
            SyntheticFeed::HOST.'/*' => fn (Request $request): PromiseInterface => $this->respond($request),
        ]);
    }

    private function respond(Request $request): PromiseInterface
    {
        preg_match('#/calendars/(\d+)\.ics#', $request->url(), $match);
        $number = (int) ($match[1] ?? 0);

        // Time Airbnb would take to answer; without it the "network" would be instant.
        if ($this->maxLatencyMs > 0) {
            usleep(random_int($this->minLatencyMs, $this->maxLatencyMs) * 1000);
        }

        return match ($this->failures[$number] ?? null) {
            'http_500' => Http::response('Internal Server Error', 500),
            'timeout' => Http::failedConnection(),
            'invalid' => Http::response('<html><body>Not a calendar</body></html>', 200),
            default => Http::response(
                SyntheticFeed::ics($this->feeds[$number] ?? []),
                200,
                ['Content-Type' => 'text/calendar'],
            ),
        };
    }

    private function createSources(): void
    {
        for ($number = 1; $number <= $this->sourceCount; $number++) {
            $property = Property::create([
                'name' => sprintf('BENCH %04d', $number),
                'address' => "Rua Fictícia {$number}",
                'postal_code' => '1000-001',
                'city' => 'Lisboa',
                'is_active' => true,
            ]);

            $source = BookingSource::create([
                'property_id' => $property->id,
                'channel' => 'airbnb',
                'type' => 'url',
                'url' => SyntheticFeed::url($number),
                'is_active' => true,
            ]);

            $this->sources[$source->id] = $number;
        }
    }

    /**
     * @param callable(): void $onSourceDone
     * @return array<string, mixed>
     */
    private function runPass(
        int $pass,
        string $label,
        int $failPercent,
        int $changePercent,
        IcalSyncService $sync,
        callable $onSourceDone,
    ): array {
        foreach ($this->sources as $number) {
            $this->feeds[$number] = SyntheticFeed::reservations($number, $this->perFeed, $changePercent);
            $this->failures[$number] = SyntheticFeed::failure($number, $pass, $failPercent);
        }

        $lastRunId = (int) SyncRun::max('id');
        $outcomes = [];
        $durations = [];
        $totals = ['created' => 0, 'updated' => 0, 'cancelled' => 0, 'events_read' => 0, 'queries' => 0];
        $passStarted = hrtime(true);

        foreach ($this->sources as $sourceId => $number) {
            $queriesBefore = $this->queries;
            $started = hrtime(true);
            $counts = ['created' => 0, 'updated' => 0, 'cancelled' => 0];

            try {
                $result = $sync->sync(BookingSource::findOrFail($sourceId));
                $status = 'ok';
                $counts = [
                    'created' => count($result->created),
                    'updated' => count($result->updated),
                    'cancelled' => count($result->cancelled),
                ];
                $this->lastGood[$number] = $this->feeds[$number];
                $totals['events_read'] += count($this->feeds[$number]);
            } catch (IcalFetchException|InvalidIcalException|SyncInProgressException $e) {
                $status = $e->errorCode->value;
            } catch (Throwable $e) {
                // Not expected: kept in the log and counted apart, so it cannot hide.
                report($e);
                $status = 'UNEXPECTED';
            }

            $durationMs = (hrtime(true) - $started) / 1e6;
            $queries = $this->queries - $queriesBefore;

            $durations[] = $durationMs;
            $outcomes[$status] = ($outcomes[$status] ?? 0) + 1;
            $totals['queries'] += $queries;

            foreach ($counts as $key => $value) {
                $totals[$key] += $value;
            }

            $this->rows[] = [
                'pass' => $pass,
                'label' => $label,
                'source_id' => $sourceId,
                'status' => $status,
                'failure_kind' => $this->failures[$number] ?? '',
                'duration_ms' => round($durationMs, 2),
                ...$counts,
                'queries' => $queries,
            ];

            $onSourceDone();
        }

        $totalMs = (hrtime(true) - $passStarted) / 1e6;
        sort($durations);
        $failed = $this->sourceCount - ($outcomes['ok'] ?? 0);

        return [
            'pass' => $pass,
            'label' => $label,
            'fail_percent' => $failPercent,
            'change_percent' => $changePercent,
            'duration_ms' => round($totalMs, 1),
            'per_source_ms' => [
                'median' => self::percentile($durations, 50),
                'p95' => self::percentile($durations, 95),
                'max' => self::percentile($durations, 100),
            ],
            'outcomes' => $outcomes,
            'events_read' => $totals['events_read'],
            'events_per_second' => $totalMs > 0 ? round($totals['events_read'] / ($totalMs / 1000), 1) : 0,
            'created' => $totals['created'],
            'updated' => $totals['updated'],
            'cancelled' => $totals['cancelled'],
            'queries' => $totals['queries'],
            'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
            'checks' => $this->checks($lastRunId, $failed),
        ];
    }

    /** @return array{mismatched_sources: int, duplicate_bookings: int, stuck_runs: int, unrecorded_failures: int} */
    private function checks(int $lastRunId, int $failed): array
    {
        $mismatched = 0;

        // After each pass, every source must hold exactly its last feed read successfully:
        // a failed read keeps the previous bookings, a successful one applies the new feed.
        foreach ($this->sources as $sourceId => $number) {
            $expected = $this->lastGood[$number] ?? [];
            $actual = Booking::where('booking_source_id', $sourceId)
                ->where('status', ReservationStatus::Reserved)
                ->get()
                ->mapWithKeys(fn (Booking $booking): array => [
                    $booking->external_uid => [
                        $booking->source_check_in_at->format('Ymd'),
                        $booking->source_check_out_at->format('Ymd'),
                    ],
                ])
                ->all();

            ksort($expected);
            ksort($actual);

            if ($expected !== $actual) {
                $mismatched++;
            }
        }

        $duplicates = DB::table('bookings')
            ->select('booking_source_id', 'external_uid')
            ->groupBy('booking_source_id', 'external_uid')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();

        $recordedFailures = SyncRun::where('id', '>', $lastRunId)->where('status', 'failed')->count();

        return [
            // Bookings lost or left over compared with the feed: a silent loss.
            'mismatched_sources' => $mismatched,
            'duplicate_bookings' => $duplicates,
            'stuck_runs' => SyncRun::where('status', 'running')->count(),
            // Every failed source must leave a failed sync run with its reason.
            'unrecorded_failures' => max(0, $failed - $recordedFailures),
        ];
    }

    /** @param list<float> $sorted */
    private static function percentile(array $sorted, int $percent): float
    {
        if ($sorted === []) {
            return 0.0;
        }

        $index = (int) ceil($percent / 100 * count($sorted)) - 1;

        return round($sorted[max(0, $index)], 2);
    }
}