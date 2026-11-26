<?php

declare(strict_types=1);

use App\Models\Task;
use App\Models\User;
use App\Modules\Checklists\Models\ChecklistTemplate;
use App\Modules\Checklists\Models\TaskChecklist;
use App\Modules\Checklists\Services\PublishChecklistVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** Creates a property, a reservation and a task for the given provider. */
function checklistTask(?int $serviceProviderId, string $type = 'checkout_cleaning', string $status = 'pending'): Task
{
    $now = now();

    $propertyId = DB::table('properties')->insertGetId([
        'name' => 'Casa checklist '.uniqid(),
        'address' => 'Rua de teste, 1',
        'postal_code' => '1000-001',
        'city' => 'Lisboa',
        'is_active' => true,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $bookingSourceId = DB::table('booking_sources')->insertGetId([
        'property_id' => $propertyId,
        'channel' => 'test',
        'type' => 'url',
        'is_active' => true,
    ]);

    $bookingId = DB::table('bookings')->insertGetId([
        'booking_source_id' => $bookingSourceId,
        'external_uid' => 'checklist-test-'.uniqid(),
        'source_check_in_at' => $now->copy()->addDay(),
        'source_check_out_at' => $now->copy()->addDays(2),
        'status' => 'reserved',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return Task::create([
        'booking_id' => $bookingId,
        'property_id' => $propertyId,
        'service_provider_id' => $serviceProviderId,
        'type' => $type,
        'status' => $status,
        'scheduled_start_at' => $now->copy()->subHour(),
    ]);
}

/** @param  array<int, array{label: string, required?: bool}>  $items */
function publishChecklist(string $taskType = 'checkout_cleaning', array $items = [], string $name = 'Limpeza'): ChecklistTemplate
{
    return app(PublishChecklistVersion::class)->handle(
        $taskType,
        $name,
        $items !== [] ? $items : [
            ['label' => 'Fazer as camas'],
            ['label' => 'Lavar a casa de banho'],
            ['label' => 'Repor amenities', 'required' => false],
        ],
        userWithRole('admin'),
    );
}

function checklistItemId(ChecklistTemplate $template, int $position): int
{
    return $template->items->firstWhere('position', $position)->id;
}

// ---------------------------------------------------------------------------
// Linking the version to the task
// ---------------------------------------------------------------------------

it('links a new task to the current checklist version of its type', function () {
    publishChecklist();
    $current = publishChecklist(name: 'Limpeza v2');
    publishChecklist('checkin_inspection', name: 'Inspeção');

    $task = checklistTask(null);

    expect(TaskChecklist::where('task_id', $task->id)->value('checklist_template_id'))->toBe($current->id);
});

it('keeps the version a task was created with after a new version is published', function () {
    $provider = createServiceProvider('checklist-keep@example.test');
    publishChecklist();
    $task = checklistTask($provider->id);

    publishChecklist(items: [['label' => 'Outro item']], name: 'Limpeza v2');

    authenticateApiUser($provider->user);

    $this->getJson("/api/my/tasks/{$task->id}/checklist")
        ->assertOk()
        ->assertJsonPath('data.version', 1)
        ->assertJsonPath('data.name', 'Limpeza')
        ->assertJsonCount(3, 'data.items');
});

it('creates tasks normally when no checklist exists for the type', function () {
    $task = checklistTask(null);

    expect(TaskChecklist::where('task_id', $task->id)->exists())->toBeFalse();

    authenticateApiUser(userWithRole('admin'));

    $this->getJson("/api/tasks/{$task->id}/checklist")
        ->assertOk()
        ->assertExactJson(['data' => null]);
});

it('links the current version on first read to open tasks created before it existed', function () {
    $task = checklistTask(null);
    publishChecklist();

    authenticateApiUser(userWithRole('supervisor'));

    $this->getJson("/api/tasks/{$task->id}/checklist")
        ->assertOk()
        ->assertJsonPath('data.version', 1);

    expect(TaskChecklist::where('task_id', $task->id)->count())->toBe(1);
});

it('does not link a checklist to finished tasks created before it existed', function (string $status) {
    $task = checklistTask(null, status: $status);
    publishChecklist();

    authenticateApiUser(userWithRole('admin'));

    $this->getJson("/api/tasks/{$task->id}/checklist")
        ->assertOk()
        ->assertExactJson(['data' => null]);
})->with(['completed', 'cancelled']);

// ---------------------------------------------------------------------------
// Reading the checklist
// ---------------------------------------------------------------------------

it('shows the checklist with progress to staff and to the assigned provider', function () {
    $provider = createServiceProvider('checklist-read@example.test');
    publishChecklist();
    $task = checklistTask($provider->id);

    authenticateApiUser(userWithRole('admin'));

    $this->getJson("/api/tasks/{$task->id}/checklist")
        ->assertOk()
        ->assertJsonPath('data.task_id', $task->id)
        ->assertJsonPath('data.required_total', 2)
        ->assertJsonPath('data.required_done', 0)
        ->assertJsonPath('data.completed', false)
        ->assertJsonPath('data.items.0.label', 'Fazer as camas')
        ->assertJsonPath('data.items.0.done', false)
        ->assertJsonPath('data.items.0.done_at', null)
        ->assertJsonPath('data.items.0.done_by', null)
        ->assertJsonPath('data.items.2.required', false);

    authenticateApiUser($provider->user);

    $this->getJson("/api/my/tasks/{$task->id}/checklist")
        ->assertOk()
        ->assertJsonPath('data.task_id', $task->id);
});

it('hides other providers tasks with a 404', function () {
    $provider = createServiceProvider('checklist-mine@example.test');
    $other = createServiceProvider('checklist-other@example.test');
    publishChecklist();
    $task = checklistTask($other->id);

    authenticateApiUser($provider->user);

    $this->getJson("/api/my/tasks/{$task->id}/checklist")->assertNotFound();
    $this->patchJson("/api/my/tasks/{$task->id}/checklist/items/1", ['done' => true])->assertNotFound();
});

it('keeps each profile on its own checklist routes', function () {
    $provider = createServiceProvider('checklist-routes@example.test');
    publishChecklist();
    $task = checklistTask($provider->id);

    authenticateApiUser($provider->user);
    $this->getJson("/api/tasks/{$task->id}/checklist")->assertForbidden();

    authenticateApiUser(userWithRole('admin'));
    $this->getJson("/api/my/tasks/{$task->id}/checklist")->assertForbidden();
});

// ---------------------------------------------------------------------------
// Marking items
// ---------------------------------------------------------------------------

it('lets the provider mark items one by one until the required ones are done', function () {
    $provider = createServiceProvider('checklist-mark@example.test');
    $template = publishChecklist();
    $task = checklistTask($provider->id, status: 'in_progress');

    authenticateApiUser($provider->user);

    $this->patchJson("/api/my/tasks/{$task->id}/checklist/items/".checklistItemId($template, 1), ['done' => true])
        ->assertOk()
        ->assertJsonPath('data.items.0.done', true)
        ->assertJsonPath('data.items.0.done_by.id', $provider->user->id)
        ->assertJsonPath('data.items.0.done_by.name', $provider->user->name)
        ->assertJsonPath('data.items.1.done', false)
        ->assertJsonPath('data.required_done', 1)
        ->assertJsonPath('data.completed', false);

    expect($this->getJson("/api/my/tasks/{$task->id}/checklist")->json('data.items.0.done_at'))->not->toBeNull();

    // The optional item does not count towards completion.
    $this->patchJson("/api/my/tasks/{$task->id}/checklist/items/".checklistItemId($template, 2), ['done' => true])
        ->assertOk()
        ->assertJsonPath('data.required_done', 2)
        ->assertJsonPath('data.completed', true)
        ->assertJsonPath('data.items.2.done', false);
});

it('clears the author and time when an item is unmarked', function () {
    $provider = createServiceProvider('checklist-unmark@example.test');
    $template = publishChecklist();
    $task = checklistTask($provider->id, status: 'in_progress');
    $itemId = checklistItemId($template, 1);

    authenticateApiUser($provider->user);

    $this->patchJson("/api/my/tasks/{$task->id}/checklist/items/{$itemId}", ['done' => true])->assertOk();

    $this->patchJson("/api/my/tasks/{$task->id}/checklist/items/{$itemId}", ['done' => false])
        ->assertOk()
        ->assertJsonPath('data.items.0.done', false)
        ->assertJsonPath('data.items.0.done_at', null)
        ->assertJsonPath('data.items.0.done_by', null);

    $this->assertDatabaseCount('task_checklist_answers', 1);
});

it('only accepts answers while the task is in progress', function (string $status) {
    $provider = createServiceProvider('checklist-status@example.test');
    $template = publishChecklist();
    $task = checklistTask($provider->id, status: $status);

    authenticateApiUser($provider->user);

    $this->patchJson("/api/my/tasks/{$task->id}/checklist/items/".checklistItemId($template, 1), ['done' => true])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');

    $this->assertDatabaseCount('task_checklist_answers', 0);
})->with(['pending', 'completed', 'cancelled']);

it('rejects items from another checklist version', function () {
    $provider = createServiceProvider('checklist-version@example.test');
    publishChecklist();
    $task = checklistTask($provider->id, status: 'in_progress');
    $newer = publishChecklist(items: [['label' => 'Item novo']], name: 'Limpeza v2');

    authenticateApiUser($provider->user);

    $this->patchJson("/api/my/tasks/{$task->id}/checklist/items/".checklistItemId($newer, 1), ['done' => true])
        ->assertNotFound();
});

it('validates the done flag', function (array $payload) {
    $provider = createServiceProvider('checklist-done@example.test');
    $template = publishChecklist();
    $task = checklistTask($provider->id, status: 'in_progress');

    authenticateApiUser($provider->user);

    $this->patchJson("/api/my/tasks/{$task->id}/checklist/items/".checklistItemId($template, 1), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('done');
})->with([
    'missing' => [[]],
    'not boolean' => [['done' => 'sim']],
]);

it('does not block task completion when the checklist is incomplete', function () {
    $provider = createServiceProvider('checklist-complete@example.test');
    publishChecklist();
    $task = checklistTask($provider->id, status: 'in_progress');

    authenticateApiUser(userWithRole('admin'));

    $this->postJson("/api/tasks/{$task->id}/complete")->assertOk();

    expect($task->fresh()->status)->toBe('completed');
});
