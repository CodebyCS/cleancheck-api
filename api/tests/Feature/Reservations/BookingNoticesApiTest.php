<?php

declare(strict_types=1);

use App\Modules\Reservations\Events\BookingUpdated;
use App\Modules\Reservations\Models\BookingNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->booking = noticeBooking(icalProperty()->id);
    $this->admin = userWithRole('admin');
    $this->provider = createServiceProvider('provider.notice@cleancheck.test');
    $this->otherProvider = createServiceProvider('other.notice@cleancheck.test');
    $this->task = noticeTask($this->booking, $this->provider->id);

    event(new BookingUpdated($this->booking->id));

    $this->providerNotice = BookingNotice::where('user_id', $this->provider->user_id)->firstOrFail();
    $this->adminNotice = BookingNotice::where('user_id', $this->admin->id)->firstOrFail();
});

it('lists only the notices of the authenticated user', function () {
    authenticateApiUser($this->provider->user);

    $this->getJson('/api/booking-notices')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->providerNotice->id)
        ->assertJsonPath('data.0.type', 'booking_updated')
        ->assertJsonPath('data.0.read', false)
        ->assertJsonPath('data.0.booking.checkIn', '2026-11-02')
        ->assertJsonPath('data.0.booking.checkOut', '2026-11-05')
        ->assertJsonPath('data.0.property.name', 'Casa Teste iCal')
        ->assertJsonPath('data.0.task.id', $this->task->id)
        ->assertJsonPath('data.0.task.available', true)
        ->assertJsonPath('meta.unreadCount', 1);
});

it('returns an empty list to a provider without notices', function () {
    authenticateApiUser($this->otherProvider->user);

    $this->getJson('/api/booking-notices')
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.unreadCount', 0);
});

it('filters unread notices', function () {
    $this->adminNotice->update(['read_at' => now()]);
    $this->booking->update(['overridden_check_out_at' => '2026-11-06']);
    event(new BookingUpdated($this->booking->id));

    authenticateApiUser($this->admin);

    $this->getJson('/api/booking-notices?unread=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.booking.checkOut', '2026-11-06')
        ->assertJsonPath('meta.unreadCount', 1);
});

it('hides notices of other users', function () {
    authenticateApiUser($this->provider->user);

    $this->getJson("/api/booking-notices/{$this->adminNotice->id}")->assertNotFound();
    $this->patchJson("/api/booking-notices/{$this->adminNotice->id}/read")->assertNotFound();

    expect($this->adminNotice->fresh()->read_at)->toBeNull();
});

it('marks a notice as read only once', function () {
    authenticateApiUser($this->provider->user);
    $url = "/api/booking-notices/{$this->providerNotice->id}/read";

    $readAt = $this->patchJson($url)
        ->assertOk()
        ->assertJsonPath('data.read', true)
        ->json('data.readAt');

    $this->travel(5)->minutes();

    $this->patchJson($url)
        ->assertOk()
        ->assertJsonPath('data.readAt', $readAt);

    $this->getJson('/api/booking-notices')->assertJsonPath('meta.unreadCount', 0);
});

it('keeps the notice but flags the task when it is no longer assigned', function () {
    $this->task->update(['service_provider_id' => $this->otherProvider->id]);

    authenticateApiUser($this->provider->user);

    $this->getJson("/api/booking-notices/{$this->providerNotice->id}")
        ->assertOk()
        ->assertJsonPath('data.task.id', $this->task->id)
        ->assertJsonPath('data.task.available', false);
});

it('requires authentication', function () {
    $this->getJson('/api/booking-notices')->assertUnauthorized();
});