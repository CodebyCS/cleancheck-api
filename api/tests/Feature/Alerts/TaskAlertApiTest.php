<?php

declare(strict_types=1);

use App\Models\Task;
use App\Modules\Alerts\Enums\AlertResolution;
use App\Modules\Alerts\Enums\AlertType;
use App\Modules\Alerts\Models\TaskAlert;
use App\Modules\Alerts\Services\DetectTaskAlerts;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createTaskAlert(Task $task, AlertType $type, array $attributes = []): TaskAlert
{
    return TaskAlert::create(array_merge([
        'task_id' => $task->id,
        'type' => $type,
        'priority' => $type->priority(),
        'reason' => 'Motivo de teste.',
        'action' => $type->action(),
        'active_key' => TaskAlert::activeKey((int) $task->id, $type),
        'detected_at' => now(),
    ], $attributes));
}

beforeEach(function () {
    $this->travelTo('2026-11-05 12:00:00');

    $this->admin = userWithRole('admin');
    $this->supervisor = userWithRole('supervisor');
    $this->provider = createServiceProvider('provider.alert@cleancheck.test');
    $this->task = noticeTask(noticeBooking(icalProperty()->id), $this->provider->id);
});

it('lists active alerts with high priority first and the counters', function () {
    createTaskAlert($this->task, AlertType::LateStart, ['detected_at' => '2026-11-05 12:00:00']);
    createTaskAlert($this->task, AlertType::DeadlineAtRisk, ['detected_at' => '2026-11-05 12:05:00']);
    authenticateApiUser($this->admin);

    $this->getJson('/api/task-alerts')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.priority', 'high')
        ->assertJsonPath('data.0.action', 'reassign_or_help')
        ->assertJsonPath('data.1.priority', 'medium')
        ->assertJsonPath('data.0.active', true)
        ->assertJsonPath('data.0.task.id', $this->task->id)
        ->assertJsonPath('data.0.task.available', true)
        ->assertJsonPath('data.0.property.name', 'Casa Teste iCal')
        ->assertJsonPath('data.0.provider.name', $this->provider->user->name)
        ->assertJsonPath('meta.activeCount', 2)
        ->assertJsonPath('meta.highCount', 1);
});

it('keeps resolved alerts only in the history', function () {
    $resolved = createTaskAlert($this->task, AlertType::LateStart, [
        'active_key' => null,
        'resolved_at' => now(),
        'resolution' => AlertResolution::TaskStarted,
    ]);
    authenticateApiUser($this->supervisor);

    $this->getJson('/api/task-alerts')
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.activeCount', 0);

    $this->getJson('/api/task-alerts?status=resolved')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $resolved->id)
        ->assertJsonPath('data.0.active', false)
        ->assertJsonPath('data.0.resolution', 'task_started');
});

it('filters alerts by priority', function () {
    createTaskAlert($this->task, AlertType::LateStart);
    createTaskAlert($this->task, AlertType::DeadlineAtRisk);
    authenticateApiUser($this->admin);

    $this->getJson('/api/task-alerts?priority=medium')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.type', 'late_start');
});

it('rejects an unknown status filter', function () {
    authenticateApiUser($this->admin);

    $this->getJson('/api/task-alerts?status=all')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');
});

it('resolves an alert by hand with a reason and does not reopen it', function () {
    $alert = createTaskAlert($this->task, AlertType::LateStart);
    authenticateApiUser($this->supervisor);

    $this->patchJson("/api/task-alerts/{$alert->id}/resolve", ['reason' => 'Prestador avisou que chega às 12:30.'])
        ->assertOk()
        ->assertJsonPath('data.active', false)
        ->assertJsonPath('data.resolution', 'manual')
        ->assertJsonPath('data.resolutionNote', 'Prestador avisou que chega às 12:30.')
        ->assertJsonPath('data.resolvedBy.id', $this->supervisor->id);

    // The task is still late, but the team already handled it.
    app(DetectTaskAlerts::class)->run();

    expect($alert->fresh()->active_key)->toBeNull()
        ->and(TaskAlert::query()->active()->count())->toBe(0);
});

it('requires a reason to resolve an alert', function () {
    $alert = createTaskAlert($this->task, AlertType::LateStart);
    authenticateApiUser($this->admin);

    $this->patchJson("/api/task-alerts/{$alert->id}/resolve", ['reason' => ''])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('reason');

    expect($alert->fresh()->resolved_at)->toBeNull();
});

it('does not resolve the same alert twice', function () {
    $alert = createTaskAlert($this->task, AlertType::LateStart);
    authenticateApiUser($this->admin);

    $this->patchJson("/api/task-alerts/{$alert->id}/resolve", ['reason' => 'Tratado por telefone.'])->assertOk();

    $this->patchJson("/api/task-alerts/{$alert->id}/resolve", ['reason' => 'Outra vez.'])
        ->assertStatus(409);

    expect($alert->fresh()->resolution_note)->toBe('Tratado por telefone.');
});

it('blocks providers and guests from the alerts', function () {
    $alert = createTaskAlert($this->task, AlertType::LateStart);

    $this->getJson('/api/task-alerts')->assertUnauthorized();

    authenticateApiUser($this->provider->user);

    $this->getJson('/api/task-alerts')->assertForbidden();
    $this->patchJson("/api/task-alerts/{$alert->id}/resolve", ['reason' => 'Não devia.'])->assertForbidden();
});