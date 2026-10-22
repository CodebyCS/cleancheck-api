<?php

declare(strict_types=1);

use App\Models\Task;
use App\Modules\Reservations\DTO\ImportResult;
use App\Modules\Reservations\Ical\Channels\AirbnbAdapter;
use App\Modules\Reservations\Models\Booking;
use App\Modules\Reservations\Models\BookingSource;
use App\Modules\Reservations\Services\BookingImportService;
use App\Modules\Tasks\Actions\CreateCheckoutCleaningTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->propertyId = icalProperty()->id;
});

function importIcalFixture(string $fixture, int $propertyId): ImportResult
{
    $adapter = new AirbnbAdapter();
    $result = $adapter->parseFeed(icalFixture($fixture), $propertyId);

    return app(BookingImportService::class)->import($propertyId, $adapter->channel(), $result);
}

it('creates one booking per reserved event', function () {
    $result = importIcalFixture('airbnb-valid.ics', $this->propertyId);

    expect($result->created)->toHaveCount(3)
        ->and($result->existing)->toBeEmpty()
        ->and($result->skipped)->toHaveCount(1);

    $this->assertDatabaseCount('bookings', 3);

    $booking = Booking::where('external_uid', 'fict-0001@cleancheck.test')->first();

    expect($booking->source_check_in_at->format('Y-m-d'))->toBe('2026-10-10')
        ->and($booking->source_check_out_at->format('Y-m-d'))->toBe('2026-10-14');
});

it('creates one checkout cleaning task per imported booking', function () {
    importIcalFixture('airbnb-valid.ics', $this->propertyId);

    $this->assertDatabaseCount('tasks', 3);

    $booking = Booking::where('external_uid', 'fict-0001@cleancheck.test')->first();
    $task = Task::where('booking_id', $booking->id)->first();

    expect($task->type)->toBe('checkout_cleaning')
        ->and($task->property_id)->toBe($this->propertyId)
        ->and($task->scheduled_start_at->format('Y-m-d H:i'))->toBe('2026-10-14 11:30')
        ->and($task->scheduled_end_at->format('Y-m-d H:i'))->toBe('2026-10-14 14:30');
});

it('does not duplicate bookings or tasks when the same file is imported twice', function () {
    importIcalFixture('airbnb-valid.ics', $this->propertyId);
    $second = importIcalFixture('airbnb-valid.ics', $this->propertyId);

    expect($second->created)->toBeEmpty()
        ->and($second->existing)->toHaveCount(3);

    $this->assertDatabaseCount('bookings', 3);
    $this->assertDatabaseCount('tasks', 3);
    $this->assertDatabaseCount('booking_sources', 1);
});

it('keeps unknown events out of the database', function () {
    $result = importIcalFixture('airbnb-invalid.ics', $this->propertyId);

    expect($result->created)->toHaveCount(1)
        ->and($result->ambiguous)->toHaveCount(1)
        ->and($result->warnings)->toHaveCount(6);

    $this->assertDatabaseCount('bookings', 1);
    $this->assertDatabaseCount('tasks', 1);
});

it('records a successful sync run', function () {
    importIcalFixture('airbnb-valid.ics', $this->propertyId);

    $source = BookingSource::first();

    $this->assertDatabaseHas('sync_runs', [
        'booking_source_id' => $source->id,
        'status' => 'success',
        'created_count' => 3,
    ]);
});

it('rolls back the import and keeps previous data when task generation fails', function () {
    importIcalFixture('airbnb-valid.ics', $this->propertyId);

    $this->mock(CreateCheckoutCleaningTask::class)
        ->shouldReceive('execute')
        ->andThrow(ValidationException::withMessages(['check_out' => 'Data de check-out inválida.']));

    expect(fn () => importIcalFixture('airbnb-invalid.ics', $this->propertyId))
        ->toThrow(ValidationException::class);

    $this->assertDatabaseCount('bookings', 3);
    $this->assertDatabaseCount('tasks', 3);
    $this->assertDatabaseMissing('bookings', ['external_uid' => 'fict-inv-0005@cleancheck.test']);
    $this->assertDatabaseHas('sync_runs', ['status' => 'failed']);
});