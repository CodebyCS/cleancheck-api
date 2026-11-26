<?php

declare(strict_types=1);

use App\Modules\Alerts\Enums\AlertResolution;
use App\Modules\Alerts\Enums\AlertType;
use App\Modules\Alerts\Models\TaskAlert;
use App\Modules\Alerts\Services\DetectTaskAlerts;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function detectAlerts(): array
{
    return app(DetectTaskAlerts::class)->run();
}

beforeEach(function () {
    $booking = noticeBooking(icalProperty()->id);
    $this->task = noticeTask($booking, null);
    $this->task->update([
        'status' => 'pending',
        'scheduled_start_at' => '2026-11-05 11:30:00',
        'scheduled_end_at' => '2026-11-05 14:30:00',
    ]);
});

it('opens nothing before the late start threshold', function () {
    $this->travelTo('2026-11-05 11:59:00');

    detectAlerts();

    expect(TaskAlert::count())->toBe(0);
});

it('opens a medium alert when the task has not started 30 minutes after its start', function () {
    $this->travelTo('2026-11-05 12:00:00');

    detectAlerts();

    $alert = TaskAlert::sole();
    expect($alert->type)->toBe(AlertType::LateStart)
        ->and($alert->priority->value)->toBe('medium')
        ->and($alert->action->value)->toBe('contact_provider')
        ->and($alert->resolved_at)->toBeNull();
});

it('does not duplicate an active alert when detection runs again', function () {
    $this->travelTo('2026-11-05 12:00:00');
    detectAlerts();

    $this->travelTo('2026-11-05 12:01:00');
    detectAlerts();

    expect(TaskAlert::count())->toBe(1);
});

it('resolves the late start alert when the task starts and keeps it in the history', function () {
    $this->travelTo('2026-11-05 12:00:00');
    detectAlerts();

    $this->task->update(['status' => 'in_progress', 'started_at' => now()]);
    $this->travelTo('2026-11-05 12:01:00');
    detectAlerts();

    $alert = TaskAlert::sole();
    expect($alert->resolved_at)->not->toBeNull()
        ->and($alert->resolution)->toBe(AlertResolution::TaskStarted)
        ->and($alert->active_key)->toBeNull();
});

it('opens a high alert 30 minutes before the end of the window', function () {
    $this->task->update(['status' => 'in_progress', 'started_at' => '2026-11-05 11:40:00']);
    $this->travelTo('2026-11-05 14:00:00');

    detectAlerts();

    $alert = TaskAlert::sole();
    expect($alert->type)->toBe(AlertType::DeadlineAtRisk)
        ->and($alert->priority->value)->toBe('high')
        ->and($alert->action->value)->toBe('reassign_or_help');
});

it('resolves the high alert when the task is completed', function () {
    $this->task->update(['status' => 'in_progress', 'started_at' => '2026-11-05 11:40:00']);
    $this->travelTo('2026-11-05 14:00:00');
    detectAlerts();

    $this->task->update(['status' => 'completed', 'completed_at' => now()]);
    $this->travelTo('2026-11-05 14:05:00');
    detectAlerts();

    expect(TaskAlert::sole()->resolution)->toBe(AlertResolution::TaskCompleted);
});

it('does not reopen an alert the team resolved by hand', function () {
    $this->travelTo('2026-11-05 12:00:00');
    detectAlerts();
    TaskAlert::sole()->update([
        'resolved_at' => now(),
        'resolution' => AlertResolution::Manual,
        'active_key' => null,
    ]);

    $this->travelTo('2026-11-05 12:10:00');
    detectAlerts();

    expect(TaskAlert::count())->toBe(1)
        ->and(TaskAlert::active()->count())->toBe(0);
});

it('opens a new alert after a rescheduled task becomes late again', function () {
    $this->travelTo('2026-11-05 12:00:00');
    detectAlerts();

    $this->task->update(['scheduled_start_at' => '2026-11-05 13:00:00']);
    $this->travelTo('2026-11-05 12:01:00');
    detectAlerts();

    $this->travelTo('2026-11-05 13:30:00');
    detectAlerts();

    expect(TaskAlert::count())->toBe(2)
        ->and(TaskAlert::orderBy('id')->first()->resolution)->toBe(AlertResolution::Rescheduled)
        ->and(TaskAlert::active()->count())->toBe(1);
});

it('ignores tasks older than the history window', function () {
    $this->travelTo('2026-11-07 12:00:00');

    detectAlerts();

    expect(TaskAlert::count())->toBe(0);
});

it('opens no high alert when there is no next check-in', function () {
    $this->task->update(['status' => 'in_progress', 'scheduled_end_at' => null]);
    $this->travelTo('2026-11-05 18:00:00');

    detectAlerts();

    expect(TaskAlert::count())->toBe(0);
});