<?php

use App\Models\Task;
use App\Models\TaskEvidence;
use App\Models\TaskExecution;
use App\Models\TaskExecutionRejection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('task_evidence');
    $this->author = userWithRole('service_provider');
    $this->provider = $this->author->serviceProvider()->create(['phone' => '910000111']);
    $this->task = Task::factory()->inProgress()->create(['service_provider_id' => $this->provider->id]);
    $this->url = "/api/my/tasks/{$this->task->id}/executions";
    $this->body = [
        'idempotency_key' => (string) Str::uuid(),
        'responses' => [['key' => 'kitchen_clean', 'value' => true]],
        'observations' => 'Initial attempt.',
        'evidence_ids' => [],
    ];
});

function taskExecutionRejectionEvidence(Task $task, int $authorId, string $name): TaskEvidence
{
    $path = 'tasks/'.$task->id.'/'.Str::uuid().'.jpg';
    Storage::disk('task_evidence')->put($path, 'fictional evidence');

    return TaskEvidence::create([
        'task_id' => $task->id,
        'uploaded_by_user_id' => $authorId,
        'kind' => 'photo',
        'disk' => 'task_evidence',
        'path' => $path,
        'mime_type' => 'image/jpeg',
        'size_bytes' => 18,
        'original_name' => $name,
    ]);
}

function createTaskExecutionForRejectionTest(): int
{
    authenticateApiUser(test()->author);

    return test()->postJson(test()->url, test()->body)->assertCreated()->json('data.id');
}

it('allows admins and supervisors to reject an execution with a reason up to 500 characters', function (string $role) {
    $evidence = taskExecutionRejectionEvidence($this->task, $this->author->id, 'before.jpg');
    $this->body['evidence_ids'] = [$evidence->id];
    $executionId = createTaskExecutionForRejectionTest();
    $staff = userWithRole($role);
    $reason = str_repeat('R', 500);
    $taskBeforeRejection = $this->task->fresh()->getAttributes();
    authenticateApiUser($staff);

    $this->postJson("/api/tasks/{$this->task->id}/executions/{$executionId}/reject", ['reason' => $reason])
        ->assertCreated()
        ->assertJsonPath('data.id', $executionId)
        ->assertJsonPath('data.task_id', $this->task->id)
        ->assertJsonPath('data.rejection.reason', $reason)
        ->assertJsonPath('data.rejection.rejected_by_user_id', $staff->id)
        ->assertJsonPath('data.correction_of_execution_id', null)
        ->assertJsonPath('data.evidence_ids', [$evidence->id]);

    $this->assertDatabaseHas('task_execution_rejections', [
        'task_execution_id' => $executionId,
        'rejected_by_user_id' => $staff->id,
        'reason' => $reason,
    ]);
    $this->getJson('/api/tasks?status=rejected')->assertOk()
        ->assertJsonPath('data.0.id', $this->task->id);
    authenticateApiUser($this->author);
    $this->getJson('/api/my/tasks?status=rejected')->assertOk()
        ->assertJsonPath('data.0.id', $this->task->id);

    $taskAfterRejection = $this->task->fresh()->getAttributes();
    expect(Arr::except($taskAfterRejection, ['status', 'updated_at']))
        ->toBe(Arr::except($taskBeforeRejection, ['status', 'updated_at']));
    expect($this->task->fresh()->status)->toBe('rejected')
        ->and($this->task->fresh()->service_provider_id)->toBe($this->provider->id)
        ->and(TaskExecution::findOrFail($executionId)->responses)->toBe($this->body['responses'])
        ->and(TaskExecution::findOrFail($executionId)->user_id)->toBe($this->author->id)
        ->and(Storage::disk('task_evidence')->exists($evidence->path))->toBeTrue();
})->with(['admin', 'supervisor']);

it('requires a non-empty reason and rejects a second rejection without overwriting the first', function () {
    $executionId = createTaskExecutionForRejectionTest();
    authenticateApiUser(userWithRole('admin'));
    $url = "/api/tasks/{$this->task->id}/executions/{$executionId}/reject";

    $this->postJson($url, [])->assertUnprocessable()->assertJsonValidationErrors('reason');
    $this->postJson($url, ['reason' => str_repeat('R', 501)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('reason')
        ->assertJsonPath('errors.reason.0', 'O motivo pode ter no máximo 500 caracteres.');
    $this->postJson($url, ['reason' => 'Initial correction request'])->assertCreated();
    $this->postJson($url, ['reason' => 'Replacement reason'])->assertConflict();

    $this->assertDatabaseCount('task_execution_rejections', 1);
    $this->assertDatabaseHas('task_execution_rejections', [
        'task_execution_id' => $executionId,
        'reason' => 'Initial correction request',
    ]);
    $this->getJson('/api/tasks?status=rejected')->assertOk()
        ->assertJsonPath('data.0.id', $this->task->id);
});

it('requires authentication to reject an execution', function () {
    $execution = TaskExecution::query()->create([
        'task_id' => $this->task->id,
        'user_id' => $this->author->id,
        'idempotency_key' => (string) Str::uuid(),
        'payload_hash' => hash('sha256', 'fixture'),
        'responses' => [],
        'send_state' => 'confirmed',
        'confirmed_at' => now(),
    ]);

    $this->postJson("/api/tasks/{$this->task->id}/executions/{$execution->id}/reject", [
        'reason' => 'Please correct this response.',
    ])->assertUnauthorized();
});

it('restricts rejection to active authenticated admin and supervisor accounts', function () {
    $executionId = createTaskExecutionForRejectionTest();
    $url = "/api/tasks/{$this->task->id}/executions/{$executionId}/reject";

    authenticateApiUser($this->author);
    $this->postJson($url, ['reason' => 'Please correct this response.'])->assertForbidden();
    $inactiveAdmin = userWithRole('admin');
    $inactiveAdmin->forceFill(['is_active' => false])->save();
    authenticateApiUser($inactiveAdmin);
    $this->postJson($url, ['reason' => 'Please correct this response.'])->assertUnauthorized();

    $this->assertDatabaseCount('task_execution_rejections', 0);
});

it('stores a correction as a new execution on the same task and preserves the rejected attempt and its evidence', function () {
    $previousEvidence = taskExecutionRejectionEvidence($this->task, $this->author->id, 'previous.jpg');
    $this->body['evidence_ids'] = [$previousEvidence->id];
    $rejectedId = createTaskExecutionForRejectionTest();
    $originalCompletionAt = $this->task->fresh()->completed_at;
    authenticateApiUser(userWithRole('supervisor'));
    $this->postJson("/api/tasks/{$this->task->id}/executions/{$rejectedId}/reject", [
        'reason' => 'Retake the kitchen photo and correct the checklist response.',
    ])->assertCreated();

    $this->assertDatabaseHas('tasks', ['id' => $this->task->id, 'status' => 'rejected']);

    authenticateApiUser($this->author);
    $newEvidenceId = $this->postJson("/api/my/tasks/{$this->task->id}/evidence", [
        'file' => Illuminate\Http\UploadedFile::fake()->create('corrected.jpg', 100, 'image/jpeg'),
    ])->assertCreated()->json('data.id');
    $corrected = array_replace($this->body, [
        'idempotency_key' => (string) Str::uuid(),
        'responses' => [['key' => 'kitchen_clean', 'value' => false]],
        'observations' => 'Corrected attempt.',
        'evidence_ids' => [$newEvidenceId],
        'correction_of_execution_id' => $rejectedId,
    ]);
    $this->travel(1)->minutes();
    $correctionId = $this->postJson($this->url, $corrected)->assertCreated()
        ->assertJsonPath('data.task_id', $this->task->id)
        ->assertJsonPath('data.correction_of_execution_id', $rejectedId)
        ->assertJsonPath('data.user_id', $this->author->id)
        ->assertJsonPath('data.evidence_ids', [$newEvidenceId])
        ->json('data.id');
    $this->postJson($this->url, $corrected)->assertOk()->assertJsonPath('data.id', $correctionId);
    $changedRetry = array_replace($corrected, ['correction_of_execution_id' => 999999]);
    $this->postJson($this->url, $changedRetry)->assertConflict();

    expect($correctionId)->not->toBe($rejectedId)
        ->and($this->task->fresh()->status)->toBe('completed')
        ->and($this->task->fresh()->completed_by_user_id)->toBe($this->author->id)
        ->and($this->task->fresh()->completed_at->gt($originalCompletionAt))->toBeTrue()
        ->and(TaskExecution::findOrFail($rejectedId)->responses)->toBe($this->body['responses'])
        ->and(TaskExecution::findOrFail($rejectedId)->evidences->sole()->id)->toBe($previousEvidence->id)
        ->and(TaskExecutionRejection::where('task_execution_id', $rejectedId)->value('reason'))
        ->toBe('Retake the kitchen photo and correct the checklist response.')
        ->and(TaskExecution::findOrFail($correctionId)->evidences->sole()->id)->toBe($newEvidenceId);
    $this->assertDatabaseCount('task_executions', 2);
    $this->assertDatabaseCount('task_execution_evidence', 2);
    Storage::disk('task_evidence')->assertExists($previousEvidence->path);
    Storage::disk('task_evidence')->assertExists(TaskEvidence::findOrFail($newEvidenceId)->path);

    authenticateApiUser(userWithRole('supervisor'));
    $this->postJson("/api/tasks/{$this->task->id}/executions/{$rejectedId}/reject", [
        'reason' => 'This older attempt must not be rejected again.',
    ])->assertUnprocessable()->assertJsonValidationErrors('execution_id');

    expect($this->task->fresh()->status)->toBe('completed')
        ->and(TaskExecutionRejection::where('task_execution_id', $rejectedId)->value('reason'))
        ->toBe('Retake the kitchen photo and correct the checklist response.')
        ->and(TaskExecutionRejection::where('task_execution_id', $correctionId)->exists())->toBeFalse();
    $this->assertDatabaseCount('task_execution_rejections', 1);

    $this->postJson("/api/tasks/{$this->task->id}/executions/{$correctionId}/reject", [
        'reason' => 'The latest corrected attempt still needs another change.',
    ])->assertCreated();

    expect($this->task->fresh()->status)->toBe('rejected')
        ->and(TaskExecutionRejection::where('task_execution_id', $correctionId)->value('reason'))
        ->toBe('The latest corrected attempt still needs another change.');
    $this->assertDatabaseCount('task_execution_rejections', 2);
});

it('allows only the newly assigned provider to inspect history and submit a correction after reassignment', function () {
    $rejectedId = createTaskExecutionForRejectionTest();
    authenticateApiUser(userWithRole('admin'));
    $this->postJson("/api/tasks/{$this->task->id}/executions/{$rejectedId}/reject", [
        'reason' => 'Please retake the photo.',
    ])->assertCreated();

    $newProviderUser = userWithRole('service_provider');
    $newProvider = $newProviderUser->serviceProvider()->create(['phone' => '910000112']);
    $this->task->update(['service_provider_id' => $newProvider->id]);
    authenticateApiUser($this->author);
    $this->getJson($this->url)->assertNotFound();
    $this->postJson($this->url, array_replace($this->body, [
        'idempotency_key' => (string) Str::uuid(),
        'correction_of_execution_id' => $rejectedId,
    ]))->assertNotFound();

    authenticateApiUser($newProviderUser);
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.0.rejection.reason', 'Please retake the photo.');
    $correction = array_replace($this->body, [
        'idempotency_key' => (string) Str::uuid(),
        'correction_of_execution_id' => $rejectedId,
        'evidence_ids' => [],
    ]);
    $this->postJson($this->url, $correction)->assertCreated()
        ->assertJsonPath('data.correction_of_execution_id', $rejectedId)
        ->assertJsonPath('data.user_id', $newProviderUser->id);
    expect($this->task->fresh()->completed_by_user_id)->toBe($newProviderUser->id);
    $this->getJson($this->url)->assertOk()
        ->assertJsonPath('data.0.correction_of_execution_id', $rejectedId)
        ->assertJsonPath('data.1.rejection.reason', 'Please retake the photo.');
});

it('rejects corrections that do not reference a rejected execution on this task and prevents duplicate corrections', function () {
    $executionId = createTaskExecutionForRejectionTest();
    authenticateApiUser($this->author);
    $correction = array_replace($this->body, [
        'idempotency_key' => (string) Str::uuid(),
        'correction_of_execution_id' => $executionId,
    ]);
    $this->postJson($this->url, $correction)->assertUnprocessable()
        ->assertJsonValidationErrors('correction_of_execution_id');

    authenticateApiUser(userWithRole('supervisor'));
    $this->postJson("/api/tasks/{$this->task->id}/executions/{$executionId}/reject", [
        'reason' => 'Please correct this attempt.',
    ])->assertCreated();
    expect($this->task->fresh()->status)->toBe('rejected');
    authenticateApiUser($this->author);
    $this->postJson($this->url, $correction)->assertCreated();
    expect($this->task->fresh()->status)->toBe('completed');
    $correction['idempotency_key'] = (string) Str::uuid();
    $this->postJson($this->url, $correction)->assertConflict();
    $this->assertDatabaseCount('task_executions', 2);
});

it('rejects an execution only when it belongs to the route task and rolls back a failed rejection write', function () {
    $executionId = createTaskExecutionForRejectionTest();
    $otherTask = Task::factory()->create(['service_provider_id' => $this->provider->id]);
    authenticateApiUser(userWithRole('admin'));
    $this->postJson("/api/tasks/{$otherTask->id}/executions/{$executionId}/reject", [
        'reason' => 'Wrong task.',
    ])->assertNotFound();

    DB::statement("CREATE TRIGGER fail_task_execution_rejection BEFORE INSERT ON task_execution_rejections BEGIN SELECT RAISE(ABORT, 'fixture persistence failure'); END");
    try {
        $this->postJson("/api/tasks/{$this->task->id}/executions/{$executionId}/reject", [
            'reason' => 'Please correct this attempt.',
        ])->assertServerError();
        expect($this->task->fresh()->status)->toBe('in_progress');
        $this->assertDatabaseCount('task_execution_rejections', 0);
        $this->assertDatabaseHas('task_executions', ['id' => $executionId, 'task_id' => $this->task->id]);
    } finally {
        DB::statement('DROP TRIGGER fail_task_execution_rejection');
    }
});
