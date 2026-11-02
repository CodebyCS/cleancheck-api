<?php

declare(strict_types=1);

use App\Modules\Reservations\Events\BookingImported;
use App\Modules\Reservations\Models\Booking;
use App\Modules\Reservations\Models\BookingSource;
use App\Modules\Reservations\Models\SyncRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function syncUrl(int $sourceId): string
{
    return "/api/booking-sources/{$sourceId}/sync";
}

function urlSource(string $url = 'https://calendar.example.com/feed.ics?s=fict'): BookingSource
{
    return BookingSource::create([
        'property_id' => icalProperty()->id,
        'channel' => 'airbnb',
        'type' => 'url',
        'url' => $url,
        'is_active' => true,
    ]);
}

beforeEach(function () {
    Event::fake([BookingImported::class]);
    Http::preventStrayRequests();
    fakeDns([
        'calendar.example.com' => ['52.10.20.30'],
        'internal.example.com' => ['10.0.0.5'],
    ]);
});

it('syncs a url source and returns the imported bookings', function () {
    Http::fake(['calendar.example.com/*' => Http::response(icalFixture('airbnb-valid.ics'))]);
    $source = urlSource();

    $this->actingAs(userWithRole('supervisor'), 'sanctum')
        ->postJson(syncUrl($source->id))
        ->assertOk()
        ->assertJsonPath('propertyId', $source->property_id)
        ->assertJsonPath('channel', 'airbnb')
        ->assertJsonCount(3, 'created')
        ->assertJsonCount(0, 'existing')
        ->assertJsonCount(1, 'skipped');

    expect(SyncRun::sole()->status)->toBe('success');
});

it('reports existing bookings when the source is synced again', function () {
    Http::fake(['calendar.example.com/*' => Http::response(icalFixture('airbnb-valid.ics'))]);
    $source = urlSource();
    $user = userWithRole('admin');

    $this->actingAs($user, 'sanctum')->postJson(syncUrl($source->id))->assertOk();

    $this->actingAs($user, 'sanctum')
        ->postJson(syncUrl($source->id))
        ->assertOk()
        ->assertJsonCount(0, 'created')
        ->assertJsonCount(3, 'existing');

    expect(Booking::count())->toBe(3);
});

it('returns 422 FETCH_FAILED and records the failure when the download fails', function () {
    Http::fake(['calendar.example.com/*' => Http::failedConnection()]);
    $source = urlSource();

    $this->actingAs(userWithRole('supervisor'), 'sanctum')
        ->postJson(syncUrl($source->id))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'FETCH_FAILED');

    expect(SyncRun::sole()->status)->toBe('failed');
});

it('returns 422 URL_NOT_ALLOWED without contacting a private host', function () {
    Http::fake();
    $source = urlSource('https://internal.example.com/feed.ics');

    $this->actingAs(userWithRole('supervisor'), 'sanctum')
        ->postJson(syncUrl($source->id))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'URL_NOT_ALLOWED');

    Http::assertNothingSent();
    expect(SyncRun::sole()->status)->toBe('failed');
});

it('returns 422 INVALID_ICAL when the url does not return a calendar', function () {
    Http::fake(['calendar.example.com/*' => Http::response('<html>Not a calendar</html>')]);
    $source = urlSource();

    $this->actingAs(userWithRole('supervisor'), 'sanctum')
        ->postJson(syncUrl($source->id))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'INVALID_ICAL');

    expect(Booking::count())->toBe(0);
});

it('returns 422 SOURCE_NOT_SYNCABLE for file and inactive sources', function (array $attributes) {
    Http::fake();
    $source = urlSource();
    $source->update($attributes);

    $this->actingAs(userWithRole('supervisor'), 'sanctum')
        ->postJson(syncUrl($source->id))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'SOURCE_NOT_SYNCABLE');

    Http::assertNothingSent();
    expect(SyncRun::count())->toBe(0);
})->with([
    'file source' => [['type' => 'file', 'url' => null]],
    'inactive source' => [['is_active' => false]],
]);

it('returns 403 for a service provider without syncing', function () {
    Http::fake();
    $source = urlSource();

    $this->actingAs(userWithRole('prestador'), 'sanctum')
        ->postJson(syncUrl($source->id))
        ->assertForbidden();

    Http::assertNothingSent();
    expect(SyncRun::count())->toBe(0);
});

it('returns 404 for a source that does not exist', function () {
    $this->actingAs(userWithRole('supervisor'), 'sanctum')
        ->postJson(syncUrl(999))
        ->assertNotFound();
});

it('returns 401 without authentication', function () {
    $source = urlSource();

    $this->postJson(syncUrl($source->id))->assertUnauthorized();

    expect(SyncRun::count())->toBe(0);
});