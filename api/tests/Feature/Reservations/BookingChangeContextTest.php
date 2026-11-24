<?php

declare(strict_types=1);

use App\Modules\Reservations\Enums\NoticeAction;
use App\Modules\Reservations\Enums\ReservationStatus;
use App\Modules\Reservations\Events\BookingCancelled;
use App\Modules\Reservations\Events\BookingUpdated;
use App\Modules\Reservations\Models\BookingNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->booking = noticeBooking(icalProperty()->id);
    $this->admin = userWithRole('admin');
    $this->provider = createServiceProvider('provider.change@cleancheck.test');
    $this->task = noticeTask($this->booking, $this->provider->id);
});

function startNoticeTask(\App\Models\Task $task): void
{
    $task->update([
        'status' => 'in_progress',
        'started_at' => '2026-11-05 11:40:00',
        'provider_notes' => 'Nota fictícia do prestador.',
    ]);
}

it('keeps a started task when the booking dates change and says so', function () {
    startNoticeTask($this->task);
    $before = $this->task->fresh();

    $this->booking->update(['overridden_check_out_at' => '2026-11-06']);
    event(new BookingUpdated($this->booking->id));

    $after = $this->task->fresh();
    $providerNotice = BookingNotice::where('user_id', $this->provider->user_id)->firstOrFail();
    $adminNotice = BookingNotice::where('user_id', $this->admin->id)->firstOrFail();

    expect($after->status)->toBe('in_progress')
        ->and($after->started_at->equalTo($before->started_at))->toBeTrue()
        ->and($after->scheduled_start_at->equalTo($before->scheduled_start_at))->toBeTrue()
        ->and($after->provider_notes)->toBe('Nota fictícia do prestador.')
        ->and($providerNotice->task_status)->toBe('in_progress')
        ->and($providerNotice->execution_preserved)->toBeTrue()
        ->and($providerNotice->action)->toBe(NoticeAction::ContinueTask)
        ->and($adminNotice->execution_preserved)->toBeTrue()
        ->and($adminNotice->action)->toBe(NoticeAction::ReviewStartedTask);
});

it('keeps a started task when the booking is cancelled and asks for the supervisor', function () {
    startNoticeTask($this->task);

    $this->booking->update(['status' => ReservationStatus::Cancelled]);
    event(new BookingCancelled($this->booking->id));

    $notice = BookingNotice::where('user_id', $this->provider->user_id)->firstOrFail();

    expect($this->task->fresh()->trashed())->toBeFalse()
        ->and($this->task->fresh()->status)->toBe('in_progress')
        ->and($notice->execution_preserved)->toBeTrue()
        ->and($notice->action)->toBe(NoticeAction::ContactSupervisor);
});

it('asks to check the new window for a pending task', function () {
    event(new BookingUpdated($this->booking->id));

    $notice = BookingNotice::where('user_id', $this->provider->user_id)->firstOrFail();

    expect($notice->task_status)->toBe('pending')
        ->and($notice->execution_preserved)->toBeFalse()
        ->and($notice->action)->toBe(NoticeAction::CheckNewSchedule);
});

it('does not show a read notice as new when the same change is processed again', function () {
    event(new BookingUpdated($this->booking->id));
    $notice = BookingNotice::where('user_id', $this->provider->user_id)->firstOrFail();
    $notice->update(['read_at' => now()]);

    $this->travel(5)->minutes();
    event(new BookingUpdated($this->booking->id));

    expect(BookingNotice::count())->toBe(2)
        ->and($notice->fresh()->read_at)->not->toBeNull()
        ->and($notice->fresh()->updated_at->equalTo($notice->updated_at))->toBeTrue();
});

it('records a cancellation once even when the job runs again', function () {
    $this->booking->update(['status' => ReservationStatus::Cancelled]);

    event(new BookingCancelled($this->booking->id));
    BookingNotice::query()->update(['read_at' => now()]);
    event(new BookingCancelled($this->booking->id));

    expect(BookingNotice::count())->toBe(2)
        ->and(BookingNotice::whereNull('read_at')->count())->toBe(0);
});

it('still reopens a notice after a real change back to earlier dates', function () {
    event(new BookingUpdated($this->booking->id));
    $first = BookingNotice::where('user_id', $this->provider->user_id)->firstOrFail();
    $first->update(['read_at' => now()]);

    $this->travel(1)->minutes();
    $this->booking->update(['overridden_check_out_at' => '2026-11-06']);
    event(new BookingUpdated($this->booking->id));

    $this->travel(1)->minutes();
    $this->booking->update(['overridden_check_out_at' => null]);
    event(new BookingUpdated($this->booking->id));

    // Processing that last change again must not reopen it a second time.
    $first->refresh()->update(['read_at' => now()]);
    $this->travel(1)->minutes();
    event(new BookingUpdated($this->booking->id));

    expect(BookingNotice::where('user_id', $this->provider->user_id)->count())->toBe(2)
        ->and($first->fresh()->read_at)->not->toBeNull();
});

it('exposes the change context in the API', function () {
    startNoticeTask($this->task);
    event(new BookingUpdated($this->booking->id));

    authenticateApiUser($this->provider->user);

    $this->getJson('/api/booking-notices')
        ->assertOk()
        ->assertJsonPath('data.0.change.taskStatus', 'in_progress')
        ->assertJsonPath('data.0.change.executionPreserved', true)
        ->assertJsonPath('data.0.change.action', 'continue_task')
        ->assertJsonPath('data.0.task.available', true);
});