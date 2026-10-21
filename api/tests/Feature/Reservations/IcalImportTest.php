<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

const ICAL_IMPORT_URL = '/api/reservations/ical/import';

it('imports a valid file and lists the created bookings', function () {
    $this->actingAs(User::factory()->make(), 'sanctum')
        ->post(ICAL_IMPORT_URL, [
            'file' => icalUpload('airbnb-valid.ics'),
            'property_id' => 1,
        ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('propertyId', 1)
        ->assertJsonCount(3, 'created')
        ->assertJsonCount(0, 'existing')
        ->assertJsonCount(1, 'skipped')
        ->assertJsonPath('created.0.externalUid', 'fict-0001@cleancheck.test')
        ->assertJsonPath('created.0.checkIn', '2026-10-10')
        ->assertJsonPath('created.0.nights', 4);

    $this->assertDatabaseCount('bookings', 3);
});

it('reports existing bookings when the same file is imported again', function () {
    $user = User::factory()->make();
    $payload = fn () => ['file' => icalUpload('airbnb-valid.ics'), 'property_id' => 1];

    $this->actingAs($user, 'sanctum')
        ->post(ICAL_IMPORT_URL, $payload(), ['Accept' => 'application/json'])
        ->assertOk();

    $this->actingAs($user, 'sanctum')
        ->post(ICAL_IMPORT_URL, $payload(), ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonCount(0, 'created')
        ->assertJsonCount(3, 'existing');

    $this->assertDatabaseCount('bookings', 3);
});

it('lists ambiguous events and warnings without saving them', function () {
    $this->actingAs(User::factory()->make(), 'sanctum')
        ->post(ICAL_IMPORT_URL, [
            'file' => icalUpload('airbnb-invalid.ics'),
            'property_id' => 1,
        ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonCount(1, 'created')
        ->assertJsonCount(1, 'ambiguous')
        ->assertJsonCount(6, 'warnings')
        ->assertJsonPath('ambiguous.0.rawSummary', 'Manutencao');

    $this->assertDatabaseCount('bookings', 1);
});

it('returns 422 and saves nothing for an invalid file', function () {
    $this->actingAs(User::factory()->make(), 'sanctum')
        ->post(ICAL_IMPORT_URL, [
            'file' => icalUpload('airbnb-not-vcalendar.ics'),
            'property_id' => 1,
        ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'INVALID_ICAL');

    $this->assertDatabaseCount('bookings', 0);
});

it('returns 401 without authentication', function () {
    $this->post(ICAL_IMPORT_URL, [
        'file' => icalUpload('airbnb-valid.ics'),
        'property_id' => 1,
    ], ['Accept' => 'application/json'])
        ->assertUnauthorized();
});