<?php

declare(strict_types=1);

use App\Modules\Properties\Models\ServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function availabilityUrl(ServiceProvider $provider): string
{
    return "/api/service-providers/{$provider->id}/availability";
}

function unavailabilitiesUrl(ServiceProvider $provider): string
{
    return "/api/service-providers/{$provider->id}/unavailabilities";
}

it('lets the provider define their weekly hours', function () {
    $provider = createServiceProvider('horario@example.test');
    authenticateApiUser($provider->user);

    $this->putJson(availabilityUrl($provider), ['intervals' => [
        ['weekday' => 2, 'start_time' => '14:00', 'end_time' => '18:00'],
        ['weekday' => 1, 'start_time' => '09:00', 'end_time' => '13:00'],
        ['weekday' => 1, 'start_time' => '13:00', 'end_time' => '17:00'],
    ]])
        ->assertOk()
        ->assertJsonCount(3, 'data.weekly')
        ->assertJsonPath('data.weekly.0.weekday', 1)
        ->assertJsonPath('data.weekly.0.start_time', '09:00')
        ->assertJsonPath('data.weekly.1.start_time', '13:00')
        ->assertJsonPath('data.weekly.2.weekday', 2);

    $this->getJson(availabilityUrl($provider))
        ->assertOk()
        ->assertJsonCount(3, 'data.weekly');
});

it('replaces the previous weekly hours', function () {
    $provider = createServiceProvider('troca@example.test');
    $provider->availabilities()->create(['weekday' => 3, 'start_time' => '08:00', 'end_time' => '12:00']);
    authenticateApiUser(userWithRole('supervisor'));

    $this->putJson(availabilityUrl($provider), ['intervals' => [
        ['weekday' => 5, 'start_time' => '10:00', 'end_time' => '16:00'],
    ]])
        ->assertOk()
        ->assertJsonCount(1, 'data.weekly')
        ->assertJsonPath('data.weekly.0.weekday', 5);

    $this->putJson(availabilityUrl($provider), ['intervals' => []])
        ->assertOk()
        ->assertJsonCount(0, 'data.weekly');

    $this->assertDatabaseCount('service_provider_availabilities', 0);
});

it('rejects invalid and overlapping intervals', function () {
    $provider = createServiceProvider('invalido@example.test');
    authenticateApiUser(userWithRole('admin'));

    $this->putJson(availabilityUrl($provider), ['intervals' => [
        ['weekday' => 8, 'start_time' => '9:00', 'end_time' => '12:00'],
    ]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['intervals.0.weekday', 'intervals.0.start_time']);

    $this->putJson(availabilityUrl($provider), ['intervals' => [
        ['weekday' => 1, 'start_time' => '12:00', 'end_time' => '09:00'],
    ]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'intervals.0.end_time' => 'A hora de fim tem de ser depois da hora de início.',
        ]);

    $this->putJson(availabilityUrl($provider), ['intervals' => [
        ['weekday' => 1, 'start_time' => '08:00', 'end_time' => '18:00'],
        ['weekday' => 1, 'start_time' => '11:00', 'end_time' => '12:00'],
        ['weekday' => 2, 'start_time' => '11:00', 'end_time' => '12:00'],
    ]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'intervals.1.start_time' => 'Este intervalo sobrepõe-se a outro do mesmo dia.',
        ])
        ->assertJsonMissingValidationErrors(['intervals.2.start_time']);

    $this->assertDatabaseCount('service_provider_availabilities', 0);
});

it('forbids another provider from seeing or changing the hours', function () {
    $provider = createServiceProvider('dono@example.test');
    $other = createServiceProvider('outro@example.test');
    authenticateApiUser($other->user);

    $this->getJson(availabilityUrl($provider))->assertForbidden();
    $this->putJson(availabilityUrl($provider), ['intervals' => []])->assertForbidden();
    $this->postJson(unavailabilitiesUrl($provider), [
        'starts_at' => '2030-01-07T00:00:00+00:00',
        'ends_at' => '2030-01-08T00:00:00+00:00',
    ])->assertForbidden();
});

it('creates unavailabilities in UTC and rejects overlaps', function () {
    $provider = createServiceProvider('ferias@example.test');
    authenticateApiUser($provider->user);

    $this->postJson(unavailabilitiesUrl($provider), [
        'starts_at' => '2030-07-01T00:00:00+01:00',
        'ends_at' => '2030-07-08T00:00:00+01:00',
        'reason' => 'Férias',
    ])
        ->assertCreated()
        ->assertJsonPath('data.starts_at', '2030-06-30T23:00:00+00:00')
        ->assertJsonPath('data.reason', 'Férias');

    $this->postJson(unavailabilitiesUrl($provider), [
        'starts_at' => '2030-07-05T09:00:00+00:00',
        'ends_at' => '2030-07-10T09:00:00+00:00',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['starts_at' => 'Já existe uma indisponibilidade neste período.']);

    $this->postJson(unavailabilitiesUrl($provider), [
        'starts_at' => '2030-08-02T00:00:00+00:00',
        'ends_at' => '2030-08-01T00:00:00+00:00',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['ends_at' => 'O fim tem de ser depois do início.']);

    $this->assertDatabaseCount('service_provider_unavailabilities', 1);
});

it('lists only unavailabilities that have not ended', function () {
    Carbon::setTestNow('2030-03-10 12:00:00');
    $provider = createServiceProvider('passado@example.test');
    $provider->unavailabilities()->create(['starts_at' => '2030-03-01 00:00:00', 'ends_at' => '2030-03-02 00:00:00']);
    $provider->unavailabilities()->create(['starts_at' => '2030-03-10 00:00:00', 'ends_at' => '2030-03-11 00:00:00']);
    authenticateApiUser(userWithRole('admin'));

    $this->getJson(availabilityUrl($provider))
        ->assertOk()
        ->assertJsonCount(1, 'data.unavailabilities')
        ->assertJsonPath('data.unavailabilities.0.starts_at', '2030-03-10T00:00:00+00:00');

    Carbon::setTestNow();
});

it('deletes an unavailability only through its own provider', function () {
    $provider = createServiceProvider('apagar@example.test');
    $other = createServiceProvider('alheio@example.test');
    $unavailability = $provider->unavailabilities()->create([
        'starts_at' => '2030-05-01 00:00:00',
        'ends_at' => '2030-05-02 00:00:00',
    ]);
    authenticateApiUser(userWithRole('admin'));

    $this->deleteJson(unavailabilitiesUrl($other)."/{$unavailability->id}")->assertNotFound();
    $this->deleteJson(unavailabilitiesUrl($provider)."/{$unavailability->id}")->assertNoContent();

    $this->assertDatabaseCount('service_provider_unavailabilities', 0);
});
