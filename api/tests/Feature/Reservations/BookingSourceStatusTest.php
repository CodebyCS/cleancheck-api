<?php

declare(strict_types=1);

use App\Modules\Reservations\Models\BookingSource;
use App\Modules\Reservations\Models\SyncRun;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function statusSource(int $propertyId, array $attributes = []): BookingSource
{
    return BookingSource::create(array_merge([
        'property_id' => $propertyId,
        'channel' => 'airbnb',
        'type' => 'url',
        'url' => 'https://www.airbnb.com/calendar/ical/111.ics?s=fict',
        'is_active' => true,
    ], $attributes));
}

function sourceRun(BookingSource $source, string $status, string $startedAt, array $attributes = []): SyncRun
{
    return SyncRun::create(array_merge([
        'booking_source_id' => $source->id,
        'status' => $status,
        'started_at' => $startedAt,
        'finished_at' => $status === 'running' ? null : $startedAt,
    ], $attributes));
}

beforeEach(function () {
    $this->property = icalProperty();
    $this->source = statusSource($this->property->id);
});

it('shows the last attempt and the last success of each source', function () {
    sourceRun($this->source, 'success', '2026-11-10 10:00:00', ['created_count' => 2]);
    sourceRun($this->source, 'failed', '2026-11-10 10:30:00', [
        'error_message' => 'Não foi possível encontrar o servidor example.invalid.',
    ]);

    authenticateApiUser(userWithRole('supervisor'));

    $this->getJson("/api/properties/{$this->property->id}/booking-sources")
        ->assertOk()
        ->assertJsonPath('data.0.lastSync.status', 'failed')
        ->assertJsonPath('data.0.lastSync.errorMessage', 'Não foi possível encontrar o servidor example.invalid.')
        ->assertJsonPath('data.0.lastSuccessAt', CarbonImmutable::parse('2026-11-10 10:00:00')->toIso8601String());
});

it('shows a source that was never synced', function () {
    authenticateApiUser(userWithRole('supervisor'));

    $this->getJson("/api/properties/{$this->property->id}/booking-sources")
        ->assertOk()
        ->assertJsonPath('data.0.lastSync', null)
        ->assertJsonPath('data.0.lastSuccessAt', null);
});

it('updates the url of a source', function () {
    authenticateApiUser(userWithRole('supervisor'));
    $newUrl = 'https://www.airbnb.com/calendar/ical/111.ics?s=fict-new';

    $this->patchJson("/api/booking-sources/{$this->source->id}", ['url' => $newUrl])
        ->assertOk()
        ->assertJsonPath('data.url', $newUrl)
        ->assertJsonPath('data.isActive', true);

    expect($this->source->fresh()->url)->toBe($newUrl);
});

it('deactivates and reactivates a source', function () {
    authenticateApiUser(userWithRole('admin'));
    $url = "/api/booking-sources/{$this->source->id}";

    $this->patchJson($url, ['is_active' => false])->assertOk()->assertJsonPath('data.isActive', false);
    $this->patchJson($url, ['is_active' => true])->assertOk()->assertJsonPath('data.isActive', true);
});

it('rejects invalid source changes', function (array $payload, string $field) {
    statusSource($this->property->id, ['url' => 'https://www.airbnb.com/calendar/ical/222.ics?s=fict']);
    authenticateApiUser(userWithRole('supervisor'));

    $this->patchJson("/api/booking-sources/{$this->source->id}", $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'empty body' => [[], 'url'],
    'not https' => [['url' => 'http://calendar.example.com/feed.ics'], 'url'],
    'url of another source' => [['url' => 'https://www.airbnb.com/calendar/ical/222.ics?s=fict'], 'url'],
    'not a boolean' => [['is_active' => 'talvez'], 'is_active'],
]);

it('keeps the same url valid for the source itself', function () {
    authenticateApiUser(userWithRole('supervisor'));

    $this->patchJson("/api/booking-sources/{$this->source->id}", ['url' => $this->source->url])
        ->assertOk();
});

it('does not edit file sources', function () {
    $fileSource = statusSource($this->property->id, ['type' => 'file', 'url' => null]);
    authenticateApiUser(userWithRole('supervisor'));

    $this->patchJson("/api/booking-sources/{$fileSource->id}", ['is_active' => false])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('source');
});

it('forbids service providers from editing sources', function () {
    authenticateApiUser(createServiceProvider('provider.source@cleancheck.test')->user);

    $this->patchJson("/api/booking-sources/{$this->source->id}", ['is_active' => false])
        ->assertForbidden();
});