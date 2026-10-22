<?php

declare(strict_types=1);

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds one demo property and two demo service providers', function () {
    $this->seed(DatabaseSeeder::class);

    $this->assertDatabaseCount('properties', 1);
    $this->assertDatabaseHas('properties', ['name' => 'Apartamento da Foz']);
    $this->assertDatabaseCount('service_providers', 2);
});

it('can run the seeders twice without duplicating records', function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    $this->assertDatabaseCount('properties', 1);
    $this->assertDatabaseCount('service_providers', 2);
});
