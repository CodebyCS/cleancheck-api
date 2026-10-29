<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
});

it('uploads a photo and returns a signed link that serves it', function () {
    $provider = createServiceProvider('ana@example.test');

    $response = $this->actingAs(userWithRole('admin'), 'sanctum')
        ->post("/api/service-providers/{$provider->id}/photo", [
            'photo' => UploadedFile::fake()->create('ana.jpg', 100, 'image/jpeg'),
        ], ['Accept' => 'application/json'])
        ->assertOk();

    $path = $provider->fresh()->photo_path;
    expect($path)->not->toBeNull();
    Storage::disk('local')->assertExists($path);

    $photoUrl = $response->json('data.photo_url');
    expect($photoUrl)->toBeString();

    $this->get($photoUrl)->assertOk();
});

it('replaces the previous photo', function () {
    $provider = createServiceProvider('ana@example.test');
    $this->actingAs(userWithRole('admin'), 'sanctum');

    $this->post("/api/service-providers/{$provider->id}/photo", [
        'photo' => UploadedFile::fake()->create('first.jpg', 100, 'image/jpeg'),
    ], ['Accept' => 'application/json'])->assertOk();
    $firstPath = $provider->fresh()->photo_path;

    $this->post("/api/service-providers/{$provider->id}/photo", [
        'photo' => UploadedFile::fake()->create('second.png', 100, 'image/png'),
    ], ['Accept' => 'application/json'])->assertOk();

    Storage::disk('local')->assertMissing($firstPath);
    Storage::disk('local')->assertExists($provider->fresh()->photo_path);
});

it('rejects files that are not allowed images', function () {
    $provider = createServiceProvider('ana@example.test');
    $this->actingAs(userWithRole('admin'), 'sanctum');

    $this->post("/api/service-providers/{$provider->id}/photo", [
        'photo' => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf'),
    ], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('photo');

    $this->post("/api/service-providers/{$provider->id}/photo", [
        'photo' => UploadedFile::fake()->create('big.jpg', 3000, 'image/jpeg'),
    ], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['photo' => 'A fotografia não pode ter mais de 2 MB.']);

    expect($provider->fresh()->photo_path)->toBeNull();
});

it('forbids a prestador from uploading photos', function () {
    $provider = createServiceProvider('ana@example.test');

    $this->actingAs($provider->user, 'sanctum')
        ->post("/api/service-providers/{$provider->id}/photo", [
            'photo' => UploadedFile::fake()->create('ana.jpg', 100, 'image/jpeg'),
        ], ['Accept' => 'application/json'])
        ->assertForbidden();
});

it('refuses the photo route without a valid signature', function () {
    $provider = createServiceProvider('ana@example.test');
    $provider->forceFill(['photo_path' => 'service-providers/1/ana.jpg'])->save();
    Storage::disk('local')->put('service-providers/1/ana.jpg', 'fake');

    $this->getJson("/api/service-providers/{$provider->id}/photo")->assertForbidden();
});
