<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
});

function sendEvidenceTrial(mixed $test, ?UploadedFile $file, array $extra = [])
{
    $payload = array_merge($file ? ['file' => $file] : [], $extra);

    return $test->post('/api/evidence-trials', $payload, ['Accept' => 'application/json']);
}

function fakeVideoFile(string $name, int $durationSeconds, string $mime = 'video/mp4', int $sizeKb = 200): UploadedFile
{
    $ftypPayload = 'isom'.pack('N', 0).'isommp42';
    $ftyp = pack('N', strlen($ftypPayload) + 8).'ftyp'.$ftypPayload;
    $movieHeader = pack('C4', 0, 0, 0, 0)
        .pack('N', 0)
        .pack('N', 0)
        .pack('N', 1000)
        .pack('N', $durationSeconds * 1000);
    $movieHeaderAtom = pack('N', strlen($movieHeader) + 8).'mvhd'.$movieHeader;
    $movieAtom = pack('N', strlen($movieHeaderAtom) + 8).'moov'.$movieHeaderAtom;

    return UploadedFile::fake()
        ->createWithContent($name, $ftyp.$movieAtom)
        ->mimeType($mime)
        ->size($sizeKb);
}

it('accepts a photo from a service provider and stores it privately', function () {
    $user = userWithRole('service_provider');
    $this->actingAs($user, 'sanctum');

    sendEvidenceTrial($this, UploadedFile::fake()->create('quarto.jpg', 500, 'image/jpeg'), ['device' => 'Pixel 7, Android 15, 4G'])
        ->assertCreated()
        ->assertJsonPath('data.kind', 'photo')
        ->assertJsonPath('data.mime_type', 'image/jpeg')
        ->assertJsonPath('data.size_bytes', 500 * 1024)
        ->assertJsonPath('data.original_name', 'quarto.jpg')
        ->assertJsonPath('data.device', 'Pixel 7, Android 15, 4G')
        ->assertJsonStructure(['data' => ['received_at']]);

    expect(Storage::disk('local')->files("evidence-trials/{$user->id}"))->toHaveCount(1);
});

it('accepts the supported formats', function (string $name, string $mime, string $kind) {
    $this->actingAs(userWithRole('service_provider'), 'sanctum');

    $file = $kind === 'video'
        ? fakeVideoFile($name, 1, $mime)
        : UploadedFile::fake()->create($name, 200, $mime);

    sendEvidenceTrial($this, $file)
        ->assertCreated()
        ->assertJsonPath('data.kind', $kind)
        ->assertJsonPath('data.device', null);
})->with([
    'png' => ['foto.png', 'image/png', 'photo'],
    'webp' => ['foto.webp', 'image/webp', 'photo'],
    'heic (iPhone)' => ['foto.heic', 'image/heic', 'photo'],
    'mp4 (Android)' => ['video.mp4', 'video/mp4', 'video'],
    'mov (iPhone)' => ['video.mov', 'video/quicktime', 'video'],
]);

it('accepts a video up to 50 MB', function () {
    $this->actingAs(userWithRole('service_provider'), 'sanctum');

    sendEvidenceTrial($this, fakeVideoFile('limpeza.mp4', 60, 'video/mp4', 50 * 1024))
        ->assertCreated()
        ->assertJsonPath('data.kind', 'video');
});

it('rejects a video longer than 60 seconds', function () {
    $this->actingAs(userWithRole('service_provider'), 'sanctum');

    sendEvidenceTrial($this, fakeVideoFile('limpeza.mp4', 61))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['file' => 'O vídeo não pode ter mais de 60 segundos.']);

    expect(Storage::disk('local')->allFiles())->toBeEmpty();
});

it('links an upload to an assigned task and records the authenticated provider', function () {
    $provider = createServiceProvider('evidence-provider@example.test');
    $task = \App\Models\Task::factory()->create(['service_provider_id' => $provider->id]);
    $this->actingAs($provider->user, 'sanctum');

    $this->post("/api/my/tasks/{$task->id}/evidence-trials", [
        'file' => fakeVideoFile('limpeza.mp4', 45),
    ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('data.is_trial', true)
        ->assertJsonPath('data.task_id', $task->id)
        ->assertJsonStructure(['data' => ['id', 'task_id', 'kind', 'received_at']]);

    $trial = \App\Models\EvidenceTrial::query()->sole();
    expect($trial->task_id)->toBe($task->id)
        ->and($trial->user_id)->toBe($provider->user_id)
        ->and(Storage::disk('local')->exists($trial->path))->toBeTrue();
});

it('rejects a task-associated upload from a provider without current assignment', function () {
    $assignedProvider = createServiceProvider('assigned-provider@example.test');
    $otherProvider = createServiceProvider('other-provider@example.test');
    $task = \App\Models\Task::factory()->create(['service_provider_id' => $assignedProvider->id]);
    $this->actingAs($otherProvider->user, 'sanctum');

    $this->post("/api/my/tasks/{$task->id}/evidence-trials", [
        'file' => fakeVideoFile('limpeza.mp4', 45),
    ], ['Accept' => 'application/json'])->assertForbidden();

    expect(\App\Models\EvidenceTrial::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBeEmpty();
});

it('rejects a task-associated upload for a nonexistent task without storing it', function () {
    $provider = createServiceProvider('missing-task-provider@example.test');
    $this->actingAs($provider->user, 'sanctum');

    $this->post('/api/my/tasks/999999/evidence-trials', [
        'file' => fakeVideoFile('limpeza.mp4', 45),
    ], ['Accept' => 'application/json'])->assertNotFound();

    expect(\App\Models\EvidenceTrial::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBeEmpty();
});

it('requires authentication on the task-associated upload route', function () {
    $provider = createServiceProvider('unauthenticated-task-provider@example.test');
    $task = \App\Models\Task::factory()->create(['service_provider_id' => $provider->id]);

    $this->post("/api/my/tasks/{$task->id}/evidence-trials", [
        'file' => fakeVideoFile('limpeza.mp4', 45),
    ], ['Accept' => 'application/json'])->assertUnauthorized();

    expect(\App\Models\EvidenceTrial::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBeEmpty();
});

it('rejects a non-provider on the task-associated upload route without storing it', function () {
    $provider = createServiceProvider('task-role-provider@example.test');
    $task = \App\Models\Task::factory()->create(['service_provider_id' => $provider->id]);
    $this->actingAs(userWithRole('admin'), 'sanctum');

    $this->post("/api/my/tasks/{$task->id}/evidence-trials", [
        'file' => fakeVideoFile('limpeza.mp4', 45),
    ], ['Accept' => 'application/json'])->assertForbidden();

    expect(\App\Models\EvidenceTrial::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBeEmpty();
});

it('rejects a video over 50 MB', function () {
    $this->actingAs(userWithRole('service_provider'), 'sanctum');

    sendEvidenceTrial($this, UploadedFile::fake()->create('limpeza.mp4', 50 * 1024 + 1, 'video/mp4'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['file' => 'O ficheiro não pode ter mais de 50 MB.']);
});

it('rejects a photo over 10 MB', function () {
    $this->actingAs(userWithRole('service_provider'), 'sanctum');

    sendEvidenceTrial($this, UploadedFile::fake()->create('quarto.jpg', 10 * 1024 + 1, 'image/jpeg'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['file' => 'A fotografia não pode ter mais de 10 MB.']);

    expect(Storage::disk('local')->allFiles())->toBeEmpty();
});

it('rejects unsupported formats', function (string $name, string $mime) {
    $this->actingAs(userWithRole('service_provider'), 'sanctum');

    sendEvidenceTrial($this, UploadedFile::fake()->create($name, 100, $mime))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('file');
})->with([
    'pdf' => ['relatorio.pdf', 'application/pdf'],
    'gif' => ['animacao.gif', 'image/gif'],
    'avi' => ['video.avi', 'video/x-msvideo'],
]);

it('requires a file', function () {
    $this->actingAs(userWithRole('service_provider'), 'sanctum');

    sendEvidenceTrial($this, null)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['file' => 'Escolha uma fotografia ou um vídeo.']);
});

it('limits the device description to 120 characters', function () {
    $this->actingAs(userWithRole('service_provider'), 'sanctum');

    sendEvidenceTrial($this, UploadedFile::fake()->create('quarto.jpg', 100, 'image/jpeg'), ['device' => str_repeat('a', 121)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('device');
});

it('requires authentication', function () {
    sendEvidenceTrial($this, UploadedFile::fake()->create('quarto.jpg', 100, 'image/jpeg'))
        ->assertUnauthorized();
});

it('is only for service providers', function (string $role) {
    $this->actingAs(userWithRole($role), 'sanctum');

    sendEvidenceTrial($this, UploadedFile::fake()->create('quarto.jpg', 100, 'image/jpeg'))
        ->assertForbidden();

    expect(Storage::disk('local')->allFiles())->toBeEmpty();
})->with(['admin', 'supervisor']);
