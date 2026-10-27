<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

const ICAL_READ_URL = '/api/reservations/ical/read';

it('returns 200 with the events of a valid file', function () {
    $this->actingAs(userWithRole('supervisor'), 'sanctum')
        ->post(ICAL_READ_URL, [
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

it('allows admins to read a file', function () {
    $this->actingAs(userWithRole('admin'), 'sanctum')
        ->post(ICAL_READ_URL, [
            'file' => icalUpload('airbnb-valid.ics'),
            'property_id' => 1,
        ], ['Accept' => 'application/json'])
        ->assertOk();
});

it('returns 200 with warnings for a file with invalid events', function () {
    $this->actingAs(userWithRole('supervisor'), 'sanctum')
        ->post(ICAL_READ_URL, [
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
    $this->actingAs(userWithRole('supervisor'), 'sanctum')
        ->post(ICAL_READ_URL, [
            'file' => icalUpload('airbnb-empty.ics'),
            'property_id' => 1,
        ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'EMPTY_FILE');
});

it('returns 422 INVALID_ICAL for a file that is not a calendar', function () {
    $this->actingAs(userWithRole('supervisor'), 'sanctum')
        ->post(ICAL_READ_URL, [
            'file' => icalUpload('airbnb-not-vcalendar.ics'),
            'property_id' => 1,
        ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'INVALID_ICAL');
});

it('returns 422 NO_FILE when no file is sent', function () {
    $this->actingAs(userWithRole('supervisor'), 'sanctum')
        ->post(ICAL_READ_URL, [
            'property_id' => 1,
        ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'NO_FILE');
});

it('returns 422 INVALID_FILE_TYPE for a file without the .ics extension', function () {
    $this->actingAs(userWithRole('supervisor'), 'sanctum')
        ->post(ICAL_READ_URL, [
            'file' => icalUpload('airbnb-valid.ics', 'calendar.txt'),
            'property_id' => 1,
        ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'INVALID_FILE_TYPE');
});

it('returns 422 PROPERTY_NOT_FOUND when property_id is missing', function () {
    $this->actingAs(userWithRole('supervisor'), 'sanctum')
        ->post(ICAL_READ_URL, [
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

it('returns 401 for an inactive account', function () {
    $user = userWithRole('supervisor');
    $user->forceFill(['is_active' => false])->save();

    $this->actingAs($user, 'sanctum')
        ->post(ICAL_READ_URL, [
            'file' => icalUpload('airbnb-valid.ics'),
            'property_id' => 1,
        ], ['Accept' => 'application/json'])
        ->assertUnauthorized();
});

it('returns 403 for a service provider', function () {
    $this->actingAs(userWithRole('prestador'), 'sanctum')
        ->post(ICAL_READ_URL, [
            'file' => icalUpload('airbnb-valid.ics'),
            'property_id' => 1,
        ], ['Accept' => 'application/json'])
        ->assertForbidden();
});