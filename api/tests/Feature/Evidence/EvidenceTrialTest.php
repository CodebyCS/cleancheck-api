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
    authenticateApiUser($user);

    sendEvidenceTrial($this, UploadedFile::fake()->create('quarto.jpg', 500, 'image/jpeg'), ['device' => 'Pixel 7, Android 15, 4G'])
        ->assertCreated()
        ->assertJsonPath('data.kind', 'photo')
        ->assertJsonPath('data.mime_type', 'image/jpeg')
        ->assertJsonPath('data.size_bytes', 500 * 1024)
        ->assertJsonPath('data.original_name', 'quarto.jpg')
        ->assertJsonPath('data.device', 'Pixel 7, Android 15, 4G')
        ->assertJsonStructure(['data' => ['id', 'received_at']]);

    expect(Storage::disk('local')->files("evidence-trials/{$user->id}"))->toHaveCount(1);
    expect(\App\Models\EvidenceTrial::query()->sole()->task_id)->toBeNull();
});

it('accepts the supported formats', function (string $name, string $mime, string $kind) {
    authenticateApiUser(userWithRole('service_provider'));

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
    'heif (iPhone)' => ['foto.heif', 'image/heif', 'photo'],
    'mp4 (Android)' => ['video.mp4', 'video/mp4', 'video'],
    'mov (iPhone)' => ['video.mov', 'video/quicktime', 'video'],
]);

it('accepts a video up to 50 MB', function () {
    authenticateApiUser(userWithRole('service_provider'));

    sendEvidenceTrial($this, fakeVideoFile('limpeza.mp4', 60, 'video/mp4', 50 * 1024))
        ->assertCreated()
        ->assertJsonPath('data.kind', 'video');
});

it('rejects a video longer than 60 seconds', function () {
    authenticateApiUser(userWithRole('service_provider'));

    sendEvidenceTrial($this, fakeVideoFile('limpeza.mp4', 61))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['file' => 'O vídeo não pode ter mais de 60 segundos.']);

    expect(Storage::disk('local')->allFiles())->toBeEmpty();
});

it('links an upload to an assigned task and records the authenticated provider', function () {
    $provider = createServiceProvider('evidence-provider@example.test');
    $task = \App\Models\Task::factory()->create([
        'service_provider_id' => $provider->id,
        'status' => 'pending',
    ]);
    $initialTaskState = $task->only(['status', 'service_provider_id']);
    $initialHistoryCount = $task->assignmentHistory()->count();
    authenticateApiUser($provider->user);

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
        ->and(Storage::disk('local')->exists($trial->path))->toBeTrue()
        ->and($task->fresh()->only(['status', 'service_provider_id']))->toEqual($initialTaskState)
        ->and($task->assignmentHistory()->count())->toBe($initialHistoryCount);
});

it('rejects an invalid file for an assigned task without storing a trial', function () {
    $provider = createServiceProvider('invalid-evidence-provider@example.test');
    $task = \App\Models\Task::factory()->create(['service_provider_id' => $provider->id]);
    authenticateApiUser($provider->user);

    $this->post("/api/my/tasks/{$task->id}/evidence-trials", [
        'file' => UploadedFile::fake()->create('relatorio.pdf', 100, 'application/pdf'),
    ], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('file');

    expect(\App\Models\EvidenceTrial::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBeEmpty();
});

it('rejects a task-associated upload from a provider without current assignment', function () {
    $assignedProvider = createServiceProvider('assigned-provider@example.test');
    $otherProvider = createServiceProvider('other-provider@example.test');
    $task = \App\Models\Task::factory()->create(['service_provider_id' => $assignedProvider->id]);
    authenticateApiUser($otherProvider->user);

    $this->post("/api/my/tasks/{$task->id}/evidence-trials", [
        'file' => fakeVideoFile('limpeza.mp4', 45),
    ], ['Accept' => 'application/json'])->assertForbidden();

    expect(\App\Models\EvidenceTrial::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBeEmpty();
});

it('rejects a task-associated upload for a nonexistent task without storing it', function () {
    $provider = createServiceProvider('missing-task-provider@example.test');
    authenticateApiUser($provider->user);

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
    authenticateApiUser(userWithRole('admin'));

    $this->post("/api/my/tasks/{$task->id}/evidence-trials", [
        'file' => fakeVideoFile('limpeza.mp4', 45),
    ], ['Accept' => 'application/json'])->assertForbidden();

    expect(\App\Models\EvidenceTrial::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBeEmpty();
});

it('rejects a video over 50 MB', function () {
    authenticateApiUser(userWithRole('service_provider'));

    sendEvidenceTrial($this, UploadedFile::fake()->create('limpeza.mp4', 50 * 1024 + 1, 'video/mp4'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['file' => 'O ficheiro não pode ter mais de 50 MB.']);
});

it('rejects a photo over 10 MB', function () {
    authenticateApiUser(userWithRole('service_provider'));

    sendEvidenceTrial($this, UploadedFile::fake()->create('quarto.jpg', 10 * 1024 + 1, 'image/jpeg'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['file' => 'A fotografia não pode ter mais de 10 MB.']);

    expect(Storage::disk('local')->allFiles())->toBeEmpty();
});

it('rejects unsupported formats', function (string $name, string $mime) {
    authenticateApiUser(userWithRole('service_provider'));

    sendEvidenceTrial($this, UploadedFile::fake()->create($name, 100, $mime))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('file');
})->with([
    'pdf' => ['relatorio.pdf', 'application/pdf'],
    'gif' => ['animacao.gif', 'image/gif'],
    'avi' => ['video.avi', 'video/x-msvideo'],
]);

it('requires a file', function () {
    authenticateApiUser(userWithRole('service_provider'));

    sendEvidenceTrial($this, null)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['file' => 'Escolha uma fotografia ou um vídeo.']);
});

it('limits the device description to 120 characters', function () {
    authenticateApiUser(userWithRole('service_provider'));

    sendEvidenceTrial($this, UploadedFile::fake()->create('quarto.jpg', 100, 'image/jpeg'), ['device' => str_repeat('a', 121)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('device');
});

it('requires authentication', function () {
    sendEvidenceTrial($this, UploadedFile::fake()->create('quarto.jpg', 100, 'image/jpeg'))
        ->assertUnauthorized();
});

it('is only for service providers', function (string $role) {
    authenticateApiUser(userWithRole($role));

    sendEvidenceTrial($this, UploadedFile::fake()->create('quarto.jpg', 100, 'image/jpeg'))
        ->assertForbidden();

    expect(Storage::disk('local')->allFiles())->toBeEmpty();
})->with(['admin', 'supervisor']);

it('allows only administrators to retrieve a private evidence trial file', function (string $role, int $expectedStatus) {
    $provider = createServiceProvider('download-provider@example.test');
    $task = \App\Models\Task::factory()->create(['service_provider_id' => $provider->id]);
    $path = "evidence-trials/tasks/{$task->id}/providers/{$provider->user_id}/capture.mp4";
    Storage::disk('local')->put($path, 'fake-video-content');
    $trial = \App\Models\EvidenceTrial::query()->create([
        'task_id' => $task->id,
        'user_id' => $provider->user_id,
        'path' => $path,
        'kind' => 'video',
        'mime_type' => 'video/mp4',
        'size_bytes' => 18,
        'original_name' => 'capture.mp4',
        'device' => null,
        'received_at' => now(),
    ]);
    authenticateApiUser(userWithRole($role));

    $response = $this->get("/api/evidence-trials/{$trial->id}/file");
    $response->assertStatus($expectedStatus);

    if ($expectedStatus === 200) {
        $response->assertDownload('evidence-trial-'.$trial->id);
        expect($response->headers->get('Content-Type'))->toContain('video/mp4');
    }
})->with([
    'administrator' => ['admin', 200],
    'supervisor' => ['supervisor', 403],
    'service provider' => ['service_provider', 403],
]);

it('requires authentication to retrieve a private evidence trial file', function () {
    $provider = createServiceProvider('guest-download-provider@example.test');
    $task = \App\Models\Task::factory()->create(['service_provider_id' => $provider->id]);
    $trial = \App\Models\EvidenceTrial::query()->create([
        'task_id' => $task->id,
        'user_id' => $provider->user_id,
        'path' => 'evidence-trials/private/capture.jpg',
        'kind' => 'photo',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 20,
        'original_name' => 'capture.jpg',
        'received_at' => now(),
    ]);

    $this->get("/api/evidence-trials/{$trial->id}/file")->assertUnauthorized();
});

it('hides internal storage paths when a trial file cannot be retrieved', function () {
    $provider = createServiceProvider('missing-file-provider@example.test');
    $task = \App\Models\Task::factory()->create(['service_provider_id' => $provider->id]);
    $trial = \App\Models\EvidenceTrial::query()->create([
        'task_id' => $task->id,
        'user_id' => $provider->user_id,
        'path' => 'evidence-trials/private/missing.jpg',
        'kind' => 'photo',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 20,
        'original_name' => 'missing.jpg',
        'received_at' => now(),
    ]);
    authenticateApiUser(userWithRole('admin'));

    $this->get("/api/evidence-trials/{$trial->id}/file")
        ->assertNotFound()
        ->assertDontSee('storage/app')
        ->assertDontSee('evidence-trials/');
});

it('removes evidence trial files and records after 30 days', function () {
    $provider = createServiceProvider('expired-trial-provider@example.test');
    $task = \App\Models\Task::factory()->create(['service_provider_id' => $provider->id]);
    $path = "evidence-trials/tasks/{$task->id}/providers/{$provider->user_id}/old.mp4";
    Storage::disk('local')->put($path, 'old-content');
    $trial = \App\Models\EvidenceTrial::query()->create([
        'task_id' => $task->id,
        'user_id' => $provider->user_id,
        'path' => $path,
        'kind' => 'video',
        'mime_type' => 'video/mp4',
        'size_bytes' => 11,
        'original_name' => 'old.mp4',
        'received_at' => now()->subDays(31),
    ]);

    $this->artisan('evidence-trials:prune')->assertExitCode(0);

    expect(\App\Models\EvidenceTrial::query()->find($trial->id))->toBeNull()
        ->and(Storage::disk('local')->exists($path))->toBeFalse();
});

it('rejects oversized uploads without storing files or exposing storage paths', function () {
    $provider = createServiceProvider('oversized-trial-provider@example.test');
    $task = \App\Models\Task::factory()->create(['service_provider_id' => $provider->id]);
    authenticateApiUser($provider->user);

    $this->post("/api/my/tasks/{$task->id}/evidence-trials", [
        'file' => UploadedFile::fake()->create('too-large.mp4', 50 * 1024 + 1, 'video/mp4'),
    ], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('file')
        ->assertDontSee('storage/app')
        ->assertDontSee('evidence-trials/');

    expect(\App\Models\EvidenceTrial::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBeEmpty();
});
it('removes expired unlinked legacy files after 30 days', function () {
    $legacyPath = 'evidence-trials/legacy/old-photo.jpg';
    Storage::disk('local')->put($legacyPath, 'legacy-content');
    $fullPath = Storage::disk('local')->path($legacyPath);
    touch($fullPath, now()->subDays(31)->timestamp);

    $this->artisan('evidence-trials:prune')->assertExitCode(0);

    expect(Storage::disk('local')->exists($legacyPath))->toBeFalse();
});
it('returns a generic storage error when the upload cannot be persisted', function () {
    $user = userWithRole('service_provider');
    $file = UploadedFile::fake()->create('quarto.jpg', 100, 'image/jpeg');
    $fileMock = Mockery::mock($file)->makePartial();
    $fileMock->shouldReceive('store')->once()->andReturn(false);

    $request = new \App\Modules\Evidence\Http\Requests\StoreEvidenceTrialRequest();
    $request->files->set('file', $fileMock);
    $request->setUserResolver(fn () => $user);

    expect(fn () => app(\App\Modules\Evidence\Http\Controllers\EvidenceTrialController::class)->store($request))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class, 'Não foi possível guardar o ficheiro do ensaio.');

    expect(\App\Models\EvidenceTrial::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBeEmpty();
});
it('returns 404 without a path when the admin requests an expired file', function () {
    $provider = createServiceProvider('expired-download-provider@example.test');
    $task = \App\Models\Task::factory()->create(['service_provider_id' => $provider->id]);
    $path = "evidence-trials/tasks/{$task->id}/providers/{$provider->user_id}/expired.mp4";
    Storage::disk('local')->put($path, 'expired-content');
    $trial = \App\Models\EvidenceTrial::query()->create([
        'task_id' => $task->id,
        'user_id' => $provider->user_id,
        'path' => $path,
        'kind' => 'video',
        'mime_type' => 'video/mp4',
        'size_bytes' => 16,
        'original_name' => 'expired.mp4',
        'received_at' => now()->subDays(31),
    ]);
    authenticateApiUser(userWithRole('admin'));

    $this->get("/api/evidence-trials/{$trial->id}/file")
        ->assertNotFound()
        ->assertDontSee('storage/app')
        ->assertDontSee('evidence-trials/');
});