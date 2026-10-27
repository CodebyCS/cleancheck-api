<?php

use App\Models\Role;
use App\Models\User;
use App\Modules\Properties\Models\ServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;
use App\Modules\Properties\Models\Property;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function icalFixture(string $name): string
{
    return file_get_contents(__DIR__ . '/Fixtures/ical/' . $name);
}

function icalUpload(string $fixture, ?string $clientName = null): UploadedFile
{
    return UploadedFile::fake()->createWithContent($clientName ?? $fixture, icalFixture($fixture));
}

function userWithRole(string $role): User
{
    return User::factory()->create([
        'role_id' => Role::where('name', $role)->firstOrFail()->id,
        'is_active' => true,
    ]);
}

function icalProperty(): Property
{
    return Property::create([
        'name' => 'Casa Teste iCal',
        'address' => 'Rua de Teste, 1',
        'postal_code' => '1000-001',
        'city' => 'Lisboa',
    ]);
}

function createServiceProvider(string $email, array $attributes = []): ServiceProvider
{
    $user = userWithRole('prestador');
    $user->update(['email' => $email]);

    return ServiceProvider::create(array_merge([
        'user_id' => $user->id,
        'phone' => '912345678',
    ], $attributes));
}
