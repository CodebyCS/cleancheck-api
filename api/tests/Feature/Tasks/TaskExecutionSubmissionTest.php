<?php

use App\Models\Task;
use App\Models\TaskEvidence;
use App\Models\TaskExecution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('task_evidence');
    $this->author = userWithRole('service_provider');
    $this->provider = $this->author->serviceProvider()->create(['phone' => '910000001']);
    $this->task = Task::factory()->inProgress()->create(['service_provider_id' => $this->provider->id]);
    $this->url = "/api/my/tasks/{$this->task->id}/executions";
    $this->body = [
        'idempotency_key' => (string) Str::uuid(),
        'responses' => [['key' => 'example_item', 'value' => true]],
        'observations' => 'Dados fictícios para validação.',
        'evidence_ids' => [],
    ];
});

function executionEvidence(Task $task, int $authorId): TaskEvidence
{
    $path = 'tasks/'.$task->id.'/'.Str::uuid().'.jpg';
    Storage::disk('task_evidence')->put($path, 'fixture');

    return TaskEvidence::create([
        'task_id' => $task->id, 'uploaded_by_user_id' => $authorId,
        'kind' => 'photo', 'disk' => 'task_evidence', 'path' => $path,
        'mime_type' => 'image/jpeg', 'size_bytes' => 7, 'original_name' => 'fixture.jpg',
    ]);
}

it('persists responses authorship and existing evidence without completing or reassigning the task', function () {
    $evidence = executionEvidence($this->task, $this->author->id);
    $this->body['evidence_ids'] = [$evidence->id];
    authenticateApiUser($this->author);
    $before = $this->task->fresh()->getAttributes();
    $response = $this->postJson($this->url, $this->body)->assertCreated()
        ->assertJsonPath('data.user_id', $this->author->id)
        ->assertJsonPath('data.send_state', 'confirmed')
        ->assertJsonPath('data.responses.0.value', true)
        ->assertJsonPath('data.evidence_ids', [$evidence->id]);
    $record = TaskExecution::findOrFail($response->json('data.id'));
    expect($record->task->booking_id)->toBe($this->task->booking_id)
        ->and($record->task->property_id)->toBe($this->task->property_id)
        ->and($this->task->fresh()->getAttributes())->toBe($before)
        ->and($record->evidences->sole()->id)->toBe($evidence->id);
    $this->getJson($this->url)->assertOk()->assertJsonCount(1, 'data')
        ->assertDontSee($evidence->path);
});

it('recovers a lost response with the same key and rejects different content', function () {
    authenticateApiUser($this->author);
    $id = $this->postJson($this->url, $this->body)->assertCreated()->json('data.id');
    $this->postJson($this->url, $this->body)->assertOk()->assertJsonPath('data.id', $id);
    $different = array_replace($this->body, ['observations' => 'Changed']);
    $this->postJson($this->url, $different)->assertConflict();
    $this->assertDatabaseCount('task_executions', 1);
    $different['idempotency_key'] = (string) Str::uuid();
    $this->postJson($this->url, $different)->assertCreated();
    expect(TaskExecution::find($id)->observations)->toBe($this->body['observations']);
    $this->assertDatabaseCount('task_executions', 2);
});

it('validates response shape and rejects spoofed author and foreign or missing evidence', function () {
    authenticateApiUser($this->author);
    $this->postJson($this->url, array_replace($this->body, [
        'user_id' => $this->author->id,
        'responses' => [['key' => 'example_item', 'value' => ['nested' => true]]],
    ]))->assertUnprocessable()->assertJsonValidationErrors(['user_id', 'responses.0.value']);
    $otherTask = Task::factory()->create(['service_provider_id' => $this->provider->id]);
    $foreign = executionEvidence($otherTask, $this->author->id);
    foreach ([$foreign->id, 999999] as $id) {
        $this->postJson($this->url, array_replace($this->body, ['evidence_ids' => [$id]]))
            ->assertUnprocessable()->assertJsonValidationErrors('evidence_ids');
    }
    $own = executionEvidence($this->task, $this->author->id);
    Storage::disk('task_evidence')->delete($own->path);
    $this->postJson($this->url, array_replace($this->body, ['evidence_ids' => [$own->id]]))
        ->assertUnprocessable()->assertJsonValidationErrors('evidence_ids');
    $this->assertDatabaseCount('task_executions', 0);
});

it('revokes reads and retries after reassignment while retaining original authorship', function () {
    authenticateApiUser($this->author);
    $id = $this->postJson($this->url, $this->body)->assertCreated()->json('data.id');
    $other = userWithRole('service_provider');
    $provider = $other->serviceProvider()->create(['phone' => '910000002']);
    $this->task->update(['service_provider_id' => $provider->id]);
    $this->getJson($this->url)->assertNotFound();
    $this->postJson($this->url, $this->body)->assertNotFound();
    authenticateApiUser($other);
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.0.user_id', $this->author->id);
    $this->body['idempotency_key'] = (string) Str::uuid();
    $this->postJson($this->url, $this->body)->assertCreated()->assertJsonPath('data.user_id', $other->id);
    expect(TaskExecution::find($id)->user_id)->toBe($this->author->id);
});

it('requires authentication active accounts and provider role for writes', function () {
    $this->postJson($this->url, $this->body)->assertUnauthorized();
    $this->getJson($this->url)->assertUnauthorized();
    authenticateApiUser(userWithRole('admin'));
    $this->postJson($this->url, $this->body)->assertForbidden();
    $this->getJson("/api/tasks/{$this->task->id}/executions")->assertOk();
    $inactive = userWithRole('service_provider');
    $inactiveProvider = $inactive->serviceProvider()->create(['phone' => '910000003']);
    $inactiveTask = Task::factory()->inProgress()->create(['service_provider_id' => $inactiveProvider->id]);
    $inactive->forceFill(['is_active' => false])->save();
    authenticateApiUser($inactive);
    $this->postJson("/api/my/tasks/{$inactiveTask->id}/executions", $this->body)->assertUnauthorized();
    $this->assertDatabaseCount('task_executions', 0);
});

it('rolls back execution and evidence associations when persistence fails', function () {
    $evidence = executionEvidence($this->task, $this->author->id);
    $this->body['evidence_ids'] = [$evidence->id];
    authenticateApiUser($this->author);
    DB::statement("CREATE TRIGGER fail_execution_link BEFORE INSERT ON task_execution_evidence BEGIN SELECT RAISE(ABORT, 'fixture persistence failure'); END");
    try {
        $this->postJson($this->url, $this->body)->assertServerError();
        $this->assertDatabaseCount('task_executions', 0);
        $this->assertDatabaseCount('task_execution_evidence', 0);
        $this->assertDatabaseHas('task_evidences', ['id' => $evidence->id]);
        Storage::disk('task_evidence')->assertExists($evidence->path);
    } finally {
        DB::statement('DROP TRIGGER fail_execution_link');
    }
});

it('preserves staff access to execution history after soft deletion without restoring provider access', function () {
    authenticateApiUser($this->author);
    $id = $this->postJson($this->url, $this->body)->assertCreated()->json('data.id');
    $this->task->delete();
    $this->getJson($this->url)->assertNotFound();
    $this->postJson($this->url, $this->body)->assertNotFound();
    authenticateApiUser(userWithRole('supervisor'));
    $this->getJson("/api/tasks/{$this->task->id}/executions")->assertOk()
        ->assertJsonPath('data.0.id', $id);
});

it('removes the newly uploaded file if evidence metadata cannot be persisted', function () {
    authenticateApiUser($this->author);
    DB::statement("CREATE TRIGGER fail_evidence_metadata BEFORE INSERT ON task_evidences BEGIN SELECT RAISE(ABORT, 'fixture persistence failure'); END");
    try {
        $this->post("/api/my/tasks/{$this->task->id}/evidence", [
            'file' => UploadedFile::fake()->create('fixture.jpg', 1, 'image/jpeg'),
        ], ['Accept' => 'application/json'])->assertServerError();
        $this->assertDatabaseCount('task_evidences', 0);
        expect(Storage::disk('task_evidence')->allFiles())->toBe([]);
    } finally {
        DB::statement('DROP TRIGGER fail_evidence_metadata');
    }
});
