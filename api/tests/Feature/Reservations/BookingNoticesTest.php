<?php

declare(strict_types=1);

use App\Models\Task;
use App\Modules\Reservations\Enums\BookingNoticeType;
use App\Modules\Reservations\Enums\ReservationStatus;
use App\Modules\Reservations\Events\BookingCancelled;
use App\Modules\Reservations\Events\BookingUpdated;
use App\Modules\Reservations\Models\Booking;
use App\Modules\Reservations\Models\BookingNotice;
use App\Modules\Reservations\Models\BookingSource;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function noticeBooking(int $propertyId): Booking
{
    $source = BookingSource::create([
        'property_id' => $propertyId,
        'channel' => 'airbnb',
        'type' => 'url',
        'url' => 'https://calendar.example.com/feed.ics?s=fict',
        'is_active' => true,
    ]);

    return Booking::create([
        'booking_source_id' => $source->id,
        'external_uid' => 'fict-notice-0001@cleancheck.test',
        'source_check_in_at' => '2026-11-02',
        'source_check_out_at' => '2026-11-05',
        'status' => ReservationStatus::Reserved,
    ]);
}

function noticeTask(Booking $booking, ?int $serviceProviderId): Task
{
    return Task::create([
        'booking_id' => $booking->id,
        'property_id' => $booking->bookingSource->property_id,
        'service_provider_id' => $serviceProviderId,
        'type' => 'checkout_cleaning',
        'status' => 'pending',
        'scheduled_start_at' => '2026-11-05 11:30:00',
    ]);
}

beforeEach(function () {
    $this->booking = noticeBooking(icalProperty()->id);
    $this->admin = userWithRole('admin');
    $this->supervisor = userWithRole('supervisor');
    $this->provider = createServiceProvider('provider.notice@cleancheck.test');
    $this->otherProvider = createServiceProvider('other.notice@cleancheck.test');
    $this->task = noticeTask($this->booking, $this->provider->id);
});

it('notifies the task provider, supervisors and admins when a booking changes', function () {
    event(new BookingUpdated($this->booking->id));

    $notices = BookingNotice::all();
    $providerNotice = $notices->firstWhere('user_id', $this->provider->user_id);

    expect($notices->pluck('user_id')->all())
        ->toEqualCanonicalizing([$this->admin->id, $this->supervisor->id, $this->provider->user_id])
        ->and($providerNotice->type)->toBe(BookingNoticeType::BookingUpdated)
        ->and($providerNotice->task_id)->toBe($this->task->id)
        ->and($providerNotice->check_in_on->toDateString())->toBe('2026-11-02')
        ->and($providerNotice->check_out_on->toDateString())->toBe('2026-11-05')
        ->and($providerNotice->read_at)->toBeNull()
        ->and($notices->firstWhere('user_id', $this->admin->id)->task_id)->toBeNull();
});

it('does not notify other providers or inactive accounts', function () {
    $inactive = userWithRole('supervisor');
    $inactive->forceFill(['is_active' => false])->save();

    event(new BookingUpdated($this->booking->id));

    $userIds = BookingNotice::pluck('user_id')->all();

    expect($userIds)->not->toContain($this->otherProvider->user_id)
        ->and($userIds)->not->toContain($inactive->id);
});

it('does not duplicate a notice for the same change', function () {
    event(new BookingUpdated($this->booking->id));
    event(new BookingUpdated($this->booking->id));

    expect(BookingNotice::count())->toBe(3);
});

it('adds a notice when the dates change again', function () {
    event(new BookingUpdated($this->booking->id));

    $this->booking->update(['overridden_check_out_at' => '2026-11-06']);
    event(new BookingUpdated($this->booking->id));

    $latest = BookingNotice::where('user_id', $this->provider->user_id)
        ->where('check_out_on', '2026-11-06')
        ->first();

    expect(BookingNotice::count())->toBe(6)
        ->and($latest)->not->toBeNull();
});

it('shows a notice as unread again when the dates return to it', function () {
    event(new BookingUpdated($this->booking->id));
    $notice = BookingNotice::where('user_id', $this->provider->user_id)->firstOrFail();
    $notice->update(['read_at' => now()]);

    $this->booking->update(['overridden_check_out_at' => '2026-11-06']);
    event(new BookingUpdated($this->booking->id));
    $this->booking->update(['overridden_check_out_at' => null]);
    event(new BookingUpdated($this->booking->id));

    expect(BookingNotice::count())->toBe(6)
        ->and($notice->fresh()->read_at)->toBeNull();
});

it('notifies the provider of a cancelled booking even after the task was removed', function () {
    $this->booking->update(['status' => ReservationStatus::Cancelled]);
    $this->task->delete();

    event(new BookingCancelled($this->booking->id));

    $notice = BookingNotice::where('user_id', $this->provider->user_id)->firstOrFail();

    expect(BookingNotice::count())->toBe(3)
        ->and($notice->type)->toBe(BookingNoticeType::BookingCancelled)
        ->and($notice->task_id)->toBe($this->task->id);
});

it('notifies only supervisors and admins when no provider is assigned', function () {
    $this->task->update(['service_provider_id' => null]);

    event(new BookingUpdated($this->booking->id));

    expect(BookingNotice::pluck('user_id')->all())
        ->toEqualCanonicalizing([$this->admin->id, $this->supervisor->id]);
});