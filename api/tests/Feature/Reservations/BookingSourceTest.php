<?php

declare(strict_types=1);

use App\Modules\Reservations\Models\BookingSource;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

const AIRBNB_FEED_URL = 'https://www.airbnb.com/calendar/ical/123456.ics?s=fict';

function bookingSourcesUrl(int $propertyId): string
{
    return "/api/properties/{$propertyId}/booking-sources";
}

beforeEach(function () {
    $this->property = icalProperty();
});

it('registers an https url source for a property', function () {
    $this->actingAs(userWithRole('supervisor'), 'sanctum')
        ->postJson(bookingSourcesUrl($this->property->id), ['url' => AIRBNB_FEED_URL])
        ->assertCreated()
        ->assertJsonPath('data.propertyId', $this->property->id)
        ->assertJsonPath('data.channel', 'airbnb')
        ->assertJsonPath('data.type', 'url')
        ->assertJsonPath('data.url', AIRBNB_FEED_URL)
        ->assertJsonPath('data.isActive', true);

    $this->assertDatabaseHas('booking_sources', [
        'property_id' => $this->property->id,
        'type' => 'url',
        'url' => AIRBNB_FEED_URL,
    ]);
});

it('rejects urls that are not https', function (string $url) {
    $this->actingAs(userWithRole('supervisor'), 'sanctum')
        ->postJson(bookingSourcesUrl($this->property->id), ['url' => $url])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('url');

    $this->assertDatabaseCount('booking_sources', 0);
})->with([
    'http' => 'http://www.airbnb.com/calendar/ical/123456.ics',
    'ftp' => 'ftp://example.com/calendar.ics',
    'not a url' => 'calendario',
]);

it('rejects the same url twice for the same property', function () {
    $user = userWithRole('supervisor');

    $this->actingAs($user, 'sanctum')
        ->postJson(bookingSourcesUrl($this->property->id), ['url' => AIRBNB_FEED_URL])
        ->assertCreated();

    $this->actingAs($user, 'sanctum')
        ->postJson(bookingSourcesUrl($this->property->id), ['url' => AIRBNB_FEED_URL])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('url');

    $this->assertDatabaseCount('booking_sources', 1);
});

it('lists only the sources of the given property', function () {
    $other = icalProperty();

    BookingSource::create(['property_id' => $this->property->id, 'channel' => 'airbnb', 'type' => 'url', 'url' => AIRBNB_FEED_URL]);
    BookingSource::create(['property_id' => $other->id, 'channel' => 'airbnb', 'type' => 'url', 'url' => AIRBNB_FEED_URL]);

    $this->actingAs(userWithRole('admin'), 'sanctum')
        ->getJson(bookingSourcesUrl($this->property->id))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.propertyId', $this->property->id);
});

it('returns 404 for a property that does not exist', function () {
    $this->actingAs(userWithRole('supervisor'), 'sanctum')
        ->getJson(bookingSourcesUrl(999))
        ->assertNotFound();
});

it('returns 403 for a service provider', function () {
    $user = userWithRole('prestador');

    $this->actingAs($user, 'sanctum')
        ->getJson(bookingSourcesUrl($this->property->id))
        ->assertForbidden();

    $this->actingAs($user, 'sanctum')
        ->postJson(bookingSourcesUrl($this->property->id), ['url' => AIRBNB_FEED_URL])
        ->assertForbidden();

    $this->assertDatabaseCount('booking_sources', 0);
});