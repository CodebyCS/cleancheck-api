<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

const ICAL_READ_URL = '/api/reservations/ical/read';

function icalUpload(string $fixture, ?string $clientName = null): UploadedFile
{
    return UploadedFile::fake()->createWithContent($clientName ?? $fixture, icalFixture($fixture));
}

function actingAsHost(): void
{
    $user = User::factory()->create([
        'is_active' => true,
    ]);

    Sanctum::actingAs($user);
}

it('returns 200 with the events of a valid file', function () {
    actingAsHost();

    $this->post(ICAL_READ_URL, [
        'file' => icalUpload('airbnb-valid.ics'),
        'property_id' => 1,
    ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('propertyId', 1)
        ->assertJsonPath('channel', 'airbnb')
        ->assertJsonCount(4, 'events')
        ->assertJsonCount(0, 'warnings')
        ->assertJsonPath('events.0.externalUid', 'fict-0001@cleancheck.test')
        ->assertJsonPath('events.0.checkIn', '2026-10-10')
        ->assertJsonPath('events.0.checkOut', '2026-10-14')
        ->assertJsonPath('events.0.nights', 4)
        ->assertJsonPath('events.0.status', 'reserved')
        ->assertJsonMissingPath('events.0.rawDescription');
});

it('returns 200 with warnings for a file with invalid events', function () {
    actingAsHost();

    $this->post(ICAL_READ_URL, [
        'file' => icalUpload('airbnb-invalid.ics'),
        'property_id' => 1,
    ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonCount(2, 'events')
        ->assertJsonCount(6, 'warnings')
        ->assertJsonPath('warnings.0.uid', null)
        ->assertJsonPath('warnings.0.code', 'MISSING_UID');
});

it('returns 422 EMPTY_FILE for an empty file', function () {
    actingAsHost();

    $this->post(ICAL_READ_URL, [
        'file' => icalUpload('airbnb-empty.ics'),
        'property_id' => 1,
    ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'EMPTY_FILE');
});

it('returns 422 INVALID_ICAL for a file that is not a calendar', function () {
    actingAsHost();

    $this->post(ICAL_READ_URL, [
        'file' => icalUpload('airbnb-not-vcalendar.ics'),
        'property_id' => 1,
    ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'INVALID_ICAL');
});

it('returns 422 NO_FILE when no file is sent', function () {
    actingAsHost();

    $this->post(ICAL_READ_URL, [
        'property_id' => 1,
    ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'NO_FILE');
});

it('returns 422 INVALID_FILE_TYPE for a file without the .ics extension', function () {
    actingAsHost();

    $this->post(ICAL_READ_URL, [
        'file' => icalUpload('airbnb-valid.ics', 'calendar.txt'),
        'property_id' => 1,
    ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'INVALID_FILE_TYPE');
});

it('returns 422 PROPERTY_NOT_FOUND when property_id is missing', function () {
    actingAsHost();

    $this->post(ICAL_READ_URL, [
        'file' => icalUpload('airbnb-valid.ics'),
    ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'PROPERTY_NOT_FOUND');
});

it('returns 401 without authentication', function () {
    $this->post(ICAL_READ_URL, [
        'file' => icalUpload('airbnb-valid.ics'),
        'property_id' => 1,
    ], ['Accept' => 'application/json'])
        ->assertUnauthorized();
});
