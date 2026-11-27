<?php

use App\Modules\Reservations\Jobs\SyncBookingSource;
use App\Models\EvidenceTrial;
use App\Modules\Properties\Models\Property;
use App\Modules\Reservations\Benchmark\SyncBenchmark;
use App\Modules\Reservations\Events\BookingCancelled;
use App\Modules\Reservations\Events\BookingImported;
use App\Modules\Reservations\Events\BookingUpdated;
use App\Modules\Reservations\Models\BookingSource;
use App\Modules\Alerts\Services\DetectTaskAlerts;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('reservations:sync {--now : Sync in this process instead of queueing}', function () {
    $sourceIds = BookingSource::where('type', 'url')->where('is_active', true)->pluck('id');

    foreach ($sourceIds as $sourceId) {
        if ($this->option('now')) {
            SyncBookingSource::dispatchSync($sourceId);
        } else {
            SyncBookingSource::dispatch($sourceId);
        }
    }

    $this->info("{$sourceIds->count()} fonte(s) iCal enviada(s) para sincronização.");
})->purpose('Sync every active iCal url source');

Schedule::command('reservations:sync')->everyThirtyMinutes()->withoutOverlapping();
Artisan::command('evidence-trials:prune', function () {
    $disk = Storage::disk('local');
    $cutoff = now()->subDays(30);
    $removed = 0;

    EvidenceTrial::query()
        ->where('received_at', '<=', $cutoff)
        ->orderBy('id')
        ->chunkById(100, function ($trials) use ($disk, &$removed): void {
            foreach ($trials as $trial) {
                if ($disk->exists($trial->path) && !$disk->delete($trial->path)) {
                    continue;
                }

                $trial->delete();
                $removed++;
            }
        });

    foreach ($disk->allFiles('evidence-trials') as $path) {
        if ($disk->lastModified($path) <= $cutoff->timestamp && $disk->delete($path)) {
            $removed++;
        }
    }

    $this->info("{$removed} ficheiro(s)/registo(s) de ensaio removido(s).");
})->purpose('Remove evidence trial files and records older than 30 days');

Schedule::command('evidence-trials:prune')->hourly()->withoutOverlapping();

Artisan::command('alerts:detect', function (DetectTaskAlerts $detector) {
    $result = $detector->run();

    $this->info("{$result['opened']} alerta(s) aberto(s), {$result['resolved']} resolvido(s).");
})->purpose('Open and resolve task delay alerts');

Artisan::command('reservations:benchmark
    {--sources=100 : Number of synthetic iCal sources}
    {--events=10 : Reservations per feed}
    {--fail=10 : Percentage of sources that fail in passes 1 and 2}
    {--change=10 : Percentage of reservations removed or moved in pass 3}
    {--latency=0 : Simulated network time per request in ms, e.g. 50-300}
    {--with-listeners : Also run the task and notice listeners in this process}
    {--fresh : Recreate the benchmark database before running}', function () {
    $connection = config('database.default');
    $database = (string) config("database.connections.{$connection}.database");

    // Hundreds of fake properties must never land in the demo or production database.
    if (app()->isProduction() || ! str_contains(strtolower(basename($database)), 'bench')) {
        $this->error("Base de dados '{$database}' recusada: use uma base só para benchmark, com 'bench' no nome.");

        return 1;
    }

    if ($this->option('fresh')) {
        $this->callSilently('migrate:fresh', ['--force' => true]);
    }

    if (Property::query()->exists()) {
        $this->error('A base de dados de benchmark já tem imóveis. Corra de novo com --fresh.');

        return 1;
    }

    $sources = max(1, (int) $this->option('sources'));
    $events = max(1, (int) $this->option('events'));
    $fail = min(100, max(0, (int) $this->option('fail')));
    $change = min(100, max(0, (int) $this->option('change')));
    $latency = explode('-', (string) $this->option('latency'), 2);
    $minLatency = max(0, (int) $latency[0]);
    $maxLatency = max($minLatency, (int) ($latency[1] ?? $latency[0]));

    if ($this->option('with-listeners')) {
        // Queued listeners run right away, so their cost is part of the measurement.
        config(['queue.default' => 'sync']);
    } else {
        // Measure the sync alone; otherwise the listeners would only pile up jobs in the queue.
        Event::fake([BookingImported::class, BookingUpdated::class, BookingCancelled::class]);
    }

    $benchmark = new SyncBenchmark($sources, $events, $fail, $change, $minLatency, $maxLatency);
    $bar = $this->output->createProgressBar($sources * 3);
    $report = $benchmark->run(fn () => $bar->advance());
    $bar->finish();
    $this->newLine(2);

    $environment = [
        'date' => now()->toIso8601String(),
        'commit' => trim(Process::run('git rev-parse --short HEAD')->output()) ?: null,
        'php' => PHP_VERSION,
        'laravel' => app()->version(),
        'database' => DB::connection()->getDriverName().' '.DB::connection()->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION),
        'queue' => config('queue.default'),
        'cache' => config('cache.default'),
        'os' => PHP_OS_FAMILY.' '.php_uname('r').' '.php_uname('m'),
    ];

    $parameters = [
        'sources' => $sources,
        'events_per_feed' => $events,
        'fail_percent' => $fail,
        'change_percent' => $change,
        'latency_ms' => [$minLatency, $maxLatency],
        'with_listeners' => (bool) $this->option('with-listeners'),
        'network' => 'simulated (Http::fake); parsing, import, locks and database are real',
    ];

    $name = sprintf('%s_s%d-e%d-f%d-c%d', now()->format('Y-m-d_His'), $sources, $events, $fail, $change);
    $directory = storage_path('app/benchmarks');
    File::ensureDirectoryExists($directory);

    File::put("{$directory}/{$name}.json", json_encode(
        ['environment' => $environment, 'parameters' => $parameters, 'passes' => $report['passes']],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
    ));

    $csv = fopen("{$directory}/{$name}.csv", 'w');
    fputcsv($csv, array_keys($report['rows'][0]), ',', '"', '');

    foreach ($report['rows'] as $row) {
        fputcsv($csv, $row, ',', '"', '');
    }

    fclose($csv);

    $this->table(
        ['Passagem', 'Duração (s)', 'Mediana (ms)', 'p95 (ms)', 'OK', 'Falhas', 'Criadas', 'Alteradas', 'Canceladas', 'Divergências', 'Duplicados'],
        array_map(fn (array $pass) => [
            "{$pass['pass']} {$pass['label']}",
            round($pass['duration_ms'] / 1000, 2),
            $pass['per_source_ms']['median'],
            $pass['per_source_ms']['p95'],
            $pass['outcomes']['ok'] ?? 0,
            $sources - ($pass['outcomes']['ok'] ?? 0),
            $pass['created'],
            $pass['updated'],
            $pass['cancelled'],
            $pass['checks']['mismatched_sources'],
            $pass['checks']['duplicate_bookings'],
        ], $report['passes']),
    );

    $this->info("Resultados em storage/app/benchmarks/{$name}.json e .csv");

    // Any lost booking, duplicate, stuck run or unrecorded failure fails the command.
    $problems = array_sum(array_merge(...array_map(fn (array $pass) => array_values($pass['checks']), $report['passes'])));

    return $problems === 0 ? 0 : 1;
})->purpose('Measure the iCal sync over synthetic sources (benchmark database only)');

Schedule::command('alerts:detect')->everyMinute()->withoutOverlapping();