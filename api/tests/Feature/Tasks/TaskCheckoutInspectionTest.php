<?php

use App\Models\Task;
use App\Models\TaskCheckoutInspection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->author = userWithRole('service_provider');
    $this->provider = $this->author->serviceProvider()->create(['phone' => '910000011']);
    $this->task = Task::factory()->inProgress()->create(['service_provider_id' => $this->provider->id]);
    $this->booking = $this->task->booking;
    $this->body = [
        'idempotency_key' => (string) Str::uuid(),
        'responses' => [['key' => 'damage_check', 'value' => 'none']],
        'observations' => 'Fictitious checkout inspection.',
        'evidence_ids' => [],
    ];
    $this->url = "/api/my/tasks/{$this->task->id}/checkout-inspection";
});

it('creates a separate checkout inspection only after the property checkout time', function () {
    $propertyId = $this->task->property_id;
    DB::table('properties')->where('id', $propertyId)->update([
        'default_check_out_time' => '10:00:00', 'timezone' => 'Europe/Lisbon',
    ]);
    $this->booking->forceFill(['overridden_check_out_at' => now()->addDay()->startOfDay()])->save();
    authenticateApiUser($this->author);
    $this->postJson($this->url, $this->body)->assertUnprocessable();
    expect(TaskCheckoutInspection::query()->count())->toBe(0);

    $this->booking->forceFill(['overridden_check_out_at' => now()->subDay()->startOfDay()])->save();
    $beforeTask = $this->task->fresh()->getAttributes();
    $response = $this->postJson($this->url, $this->body)->assertCreated()
        ->assertJsonPath('data.user_id', $this->author->id)
        ->assertJsonPath('data.task_id', $this->task->id)
        ->assertJsonPath('data.responses.0.key', 'damage_check');
    expect($this->task->fresh()->getAttributes())->toBe($beforeTask)
        ->and($this->task->executions()->count())->toBe(0)
        ->and(TaskCheckoutInspection::findOrFail($response->json('data.id'))->task->booking_id)->toBe($this->booking->id);
});

it('makes checkout inspection retries idempotent and permits only one inspection per cleaning task', function () {
    DB::table('properties')->where('id', $this->task->property_id)->update(['default_check_out_time' => '10:00:00']);
    $this->booking->forceFill(['overridden_check_out_at' => now()->subDay()->startOfDay()])->save();
    authenticateApiUser($this->author);
    $first = $this->postJson($this->url, $this->body)->assertCreated();
    $this->postJson($this->url, $this->body)->assertOk()->assertJsonPath('data.id', $first->json('data.id'));
    $this->postJson($this->url, array_replace($this->body, ['observations' => 'different']))->assertConflict();
    $this->postJson($this->url, array_replace($this->body, ['idempotency_key' => (string) Str::uuid()]))->assertConflict();
    $this->assertDatabaseCount('task_checkout_inspections', 1);
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.id', $first->json('data.id'));
});

it('rejects non-cleaning tasks and hides the inspection after provider reassignment', function () {
    DB::table('properties')->where('id', $this->task->property_id)->update(['default_check_out_time' => '10:00:00']);
    $this->booking->forceFill(['overridden_check_out_at' => now()->subDay()->startOfDay()])->save();
    authenticateApiUser($this->author);
    $inspectionTask = Task::factory()->checkinInspection()->create(['service_provider_id' => $this->provider->id]);
    $this->postJson("/api/my/tasks/{$inspectionTask->id}/checkout-inspection", $this->body)->assertUnprocessable();

    $other = userWithRole('service_provider');
    $otherProvider = $other->serviceProvider()->create(['phone' => '910000012']);
    $this->postJson($this->url, $this->body)->assertCreated();
    $this->task->update(['service_provider_id' => $otherProvider->id]);
    $this->getJson($this->url)->assertNotFound();
    authenticateApiUser($other);
    $this->getJson("/api/my/tasks/{$this->task->id}/checkout-inspection")->assertOk()
        ->assertJsonPath('data.user_id', $this->author->id);
});
