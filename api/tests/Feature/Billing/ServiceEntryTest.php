<?php

declare(strict_types=1);

use App\Models\Task;
use App\Modules\Billing\Models\Rate;
use App\Modules\Billing\Models\ServiceEntry;
use App\Modules\Billing\Services\RecordServiceEntry;
use App\Modules\Properties\Models\Property;
use App\Modules\Properties\Models\ServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function billingProperty(array $attributes = []): Property
{
    return Property::create(array_merge([
        'name' => 'Casa Lançamentos',
        'address' => 'Rua Fictícia 60',
        'postal_code' => '4050-123',
        'city' => 'Porto',
    ], $attributes));
}

/** Task in progress, assigned to the provider, on 2026-12-10 at 11:30 UTC. */
function billingTask(Property $property, ServiceProvider $provider, array $attributes = []): Task
{
    return Task::factory()->create(array_merge([
        'property_id' => $property->id,
        'service_provider_id' => $provider->id,
        'status' => 'in_progress',
        'started_at' => '2026-12-10 11:00:00',
        'scheduled_start_at' => '2026-12-10 11:30:00',
        'scheduled_end_at' => '2026-12-10 14:30:00',
    ], $attributes));
}

function completeAsStaff(Task $task): \Illuminate\Testing\TestResponse
{
    return test()->postJson("/api/tasks/{$task->id}/complete");
}

it('creates one entry when a task is completed and not again on repeat', function () {
    $property = billingProperty();
    $provider = createServiceProvider('lancamento@example.test');
    $rate = Rate::create(['task_type' => 'checkout_cleaning', 'amount' => 45, 'valid_from' => '2026-01-01']);
    $task = billingTask($property, $provider);
    authenticateApiUser(userWithRole('admin'));

    completeAsStaff($task)->assertOk();
    completeAsStaff($task)->assertOk();
    app(RecordServiceEntry::class)->forTask($task->id);

    expect(ServiceEntry::count())->toBe(1);

    $entry = ServiceEntry::firstOrFail();

    expect($entry->task_id)->toBe($task->id)
        ->and($entry->service_provider_id)->toBe($provider->id)
        ->and($entry->property_id)->toBe($property->id)
        ->and($entry->amount)->toBe('45.00')
        ->and($entry->currency)->toBe('EUR')
        ->and($entry->amount_source)->toBe(ServiceEntry::SOURCE_RATE)
        ->and($entry->rate_id)->toBe($rate->id)
        ->and($entry->service_date->toDateString())->toBe('2026-12-10');
});

it('creates the entry when the provider completes the task in the mobile app', function () {
    $property = billingProperty(['base_cleaning_amount' => 38]);
    $provider = createServiceProvider('mobile@example.test');
    $task = billingTask($property, $provider);
    authenticateApiUser($provider->user);

    $this->postJson("/api/my/tasks/{$task->id}/complete")->assertOk();

    expect(ServiceEntry::where('task_id', $task->id)->value('amount_source'))
        ->toBe(ServiceEntry::SOURCE_PROPERTY_BASE);
});

it('uses the agreed amount first, then the property rate, then the general rate, then the base amount', function () {
    $provider = createServiceProvider('ordem@example.test');
    $property = billingProperty(['base_cleaning_amount' => 30]);
    $general = Rate::create(['task_type' => 'checkout_cleaning', 'amount' => 40, 'valid_from' => '2026-01-01']);
    $own = Rate::create([
        'property_id' => $property->id,
        'task_type' => 'checkout_cleaning',
        'amount' => 55,
        'currency' => 'BRL',
        'valid_from' => '2026-01-01',
    ]);
    $other = billingProperty(['name' => 'Outra casa', 'base_cleaning_amount' => 30]);
    $withoutRate = billingProperty(['name' => 'Casa sem tarifa', 'base_cleaning_amount' => 30]);
    authenticateApiUser(userWithRole('admin'));

    $agreed = billingTask($property, $provider, ['agreed_amount' => 70]);
    $byOwnRate = billingTask($property, $provider);
    $byGeneralRate = billingTask($other, $provider);
    $inspection = billingTask($withoutRate, $provider, ['type' => 'checkin_inspection']);

    foreach ([$agreed, $byOwnRate, $byGeneralRate, $inspection] as $task) {
        completeAsStaff($task)->assertOk();
    }

    $entry = fn (Task $task) => ServiceEntry::where('task_id', $task->id)->firstOrFail();

    expect($entry($agreed)->amount)->toBe('70.00')
        ->and($entry($agreed)->amount_source)->toBe(ServiceEntry::SOURCE_TASK_AGREED_AMOUNT)
        ->and($entry($agreed)->currency)->toBe('BRL')
        ->and($entry($byOwnRate)->amount)->toBe('55.00')
        ->and($entry($byOwnRate)->rate_id)->toBe($own->id)
        ->and($entry($byGeneralRate)->amount)->toBe('40.00')
        ->and($entry($byGeneralRate)->rate_id)->toBe($general->id)
        ->and($entry($inspection)->amount)->toBe('30.00')
        ->and($entry($inspection)->amount_source)->toBe(ServiceEntry::SOURCE_PROPERTY_BASE);
});

it('applies the rate in force on the service date and keeps past entries when rates change', function () {
    $property = billingProperty();
    $provider = createServiceProvider('historico@example.test');
    Rate::create(['task_type' => 'checkout_cleaning', 'amount' => 40, 'valid_from' => '2026-01-01']);
    Rate::create(['task_type' => 'checkout_cleaning', 'amount' => 99, 'valid_from' => '2027-01-01']);
    $task = billingTask($property, $provider);
    authenticateApiUser(userWithRole('admin'));

    completeAsStaff($task)->assertOk();

    // A new rate and a new base amount after the service do not touch the entry.
    $this->postJson('/api/rates', [
        'task_type' => 'checkout_cleaning',
        'amount' => 50,
        'valid_from' => '2026-12-01',
    ])->assertCreated();
    $property->update(['base_cleaning_amount' => 80]);
    $task->update(['agreed_amount' => 120]);

    expect(ServiceEntry::where('task_id', $task->id)->firstOrFail()->amount)->toBe('40.00');
});

it('uses the property timezone for the service date', function () {
    // 01:00 UTC on 11 December is still 10 December in São Paulo.
    $property = billingProperty(['timezone' => 'America/Sao_Paulo', 'base_cleaning_amount' => 30]);
    $provider = createServiceProvider('brasil@example.test');
    $task = billingTask($property, $provider, [
        'scheduled_start_at' => '2026-12-11 01:00:00',
        'scheduled_end_at' => '2026-12-11 03:00:00',
    ]);
    authenticateApiUser(userWithRole('admin'));

    completeAsStaff($task)->assertOk();

    expect(ServiceEntry::where('task_id', $task->id)->firstOrFail()->service_date->toDateString())
        ->toBe('2026-12-10');
});

it('does not create entries for tasks that are not eligible or have no amount', function () {
    $property = billingProperty();
    $provider = createServiceProvider('semvalor@example.test');
    $withoutAmount = billingTask($property, $provider);
    $pending = billingTask($property, $provider, ['status' => 'pending']);
    $cancelled = billingTask($property, $provider, ['status' => 'cancelled']);
    $completedWithoutProvider = billingTask($property, $provider, ['status' => 'completed', 'service_provider_id' => null]);
    authenticateApiUser(userWithRole('admin'));

    completeAsStaff($withoutAmount)->assertOk();

    $service = app(RecordServiceEntry::class);

    expect($service->forTask($pending->id))->toBeNull()
        ->and($service->forTask($cancelled->id))->toBeNull()
        ->and($service->forTask($completedWithoutProvider->id))->toBeNull()
        ->and(ServiceEntry::count())->toBe(0);
});

it('retries the entry after the missing rate is created', function () {
    $property = billingProperty();
    $provider = createServiceProvider('retry@example.test');
    $task = billingTask($property, $provider);
    authenticateApiUser(userWithRole('supervisor'));

    completeAsStaff($task)->assertOk();

    $this->postJson("/api/tasks/{$task->id}/service-entry")
        ->assertUnprocessable()
        ->assertJsonPath('message', 'A tarefa não é elegível ou não tem valor definido (valor combinado, tarifa ou valor base).');

    Rate::create(['task_type' => 'checkout_cleaning', 'amount' => 42, 'valid_from' => '2026-01-01']);

    $this->postJson("/api/tasks/{$task->id}/service-entry")
        ->assertCreated()
        ->assertJsonPath('data.task_id', $task->id)
        ->assertJsonPath('data.amount', '42.00');

    $this->postJson("/api/tasks/{$task->id}/service-entry")->assertOk();
    $this->postJson('/api/tasks/999999/service-entry')->assertNotFound();

    expect(ServiceEntry::count())->toBe(1);
});

it('lists entries for review with totals per currency and missing tasks', function () {
    $porto = billingProperty(['base_cleaning_amount' => 30]);
    $saoPaulo = billingProperty(['name' => 'Casa SP', 'base_cleaning_amount' => 30]);
    Rate::create(['property_id' => $saoPaulo->id, 'task_type' => 'checkout_cleaning', 'amount' => 150, 'currency' => 'BRL', 'valid_from' => '2026-01-01']);
    $first = createServiceProvider('um@example.test');
    $second = createServiceProvider('dois@example.test');
    authenticateApiUser(userWithRole('admin'));

    completeAsStaff(billingTask($porto, $first))->assertOk();
    completeAsStaff(billingTask($porto, $first, ['agreed_amount' => 45]))->assertOk();
    completeAsStaff(billingTask($saoPaulo, $second))->assertOk();
    completeAsStaff(billingTask($porto, $first, [
        'scheduled_start_at' => '2026-11-10 11:30:00',
        'scheduled_end_at' => '2026-11-10 14:30:00',
    ]))->assertOk();
    // Completed without passing through the completion (e.g. old data): no entry yet.
    $missing = billingTask($porto, $second, ['status' => 'completed']);

    $this->getJson('/api/service-entries?month=2026-12')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('meta.totals.0.currency', 'BRL')
        ->assertJsonPath('meta.totals.0.total', '150.00')
        ->assertJsonPath('meta.totals.1.currency', 'EUR')
        ->assertJsonPath('meta.totals.1.entries', 2)
        ->assertJsonPath('meta.totals.1.total', '75.00')
        ->assertJsonPath('meta.tasks_without_entry', [$missing->id]);

    $this->getJson("/api/service-entries?month=2026-12&service_provider_id={$first->id}")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.service_provider_name', $first->user->name)
        ->assertJsonPath('data.0.property_name', 'Casa Lançamentos')
        ->assertJsonPath('meta.tasks_without_entry', []);

    $this->getJson('/api/service-entries?month=2026-11')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->getJson('/api/service-entries?month=2026-13')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['month' => 'O mês deve estar no formato AAAA-MM.']);
});

it('uses the property timezone for the month of entries and of tasks without entry', function () {
    // 1 December 01:00/02:00 UTC is still 30 November in São Paulo (UTC-3).
    $saoPaulo = billingProperty(['name' => 'Casa SP', 'timezone' => 'America/Sao_Paulo', 'base_cleaning_amount' => 30]);
    $lisbon = billingProperty(['name' => 'Casa Lisboa', 'base_cleaning_amount' => 30]);
    $provider = createServiceProvider('fuso@example.test');
    authenticateApiUser(userWithRole('admin'));

    $withEntry = billingTask($saoPaulo, $provider, [
        'scheduled_start_at' => '2026-12-01 02:00:00',
        'scheduled_end_at' => '2026-12-01 05:00:00',
    ]);
    completeAsStaff($withEntry)->assertOk();

    // Completed without passing through the completion: no entry yet.
    $missingSaoPaulo = billingTask($saoPaulo, $provider, [
        'status' => 'completed',
        'scheduled_start_at' => '2026-12-01 01:00:00',
        'scheduled_end_at' => '2026-12-01 04:00:00',
    ]);
    $missingLisbon = billingTask($lisbon, $provider, [
        'status' => 'completed',
        'scheduled_start_at' => '2026-12-01 00:30:00',
        'scheduled_end_at' => '2026-12-01 03:00:00',
    ]);

    $this->getJson('/api/service-entries?month=2026-11')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.task_id', $withEntry->id)
        ->assertJsonPath('data.0.service_date', '2026-11-30')
        ->assertJsonPath('meta.tasks_without_entry', [$missingSaoPaulo->id]);

    $this->getJson('/api/service-entries?month=2026-12')
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.tasks_without_entry', [$missingLisbon->id]);
});

it('forbids service providers from the review and the retry', function () {
    $provider = createServiceProvider('proibido@example.test');
    $task = billingTask(billingProperty(), $provider, ['status' => 'completed']);
    authenticateApiUser($provider->user);

    $this->getJson('/api/service-entries')->assertForbidden();
    $this->postJson("/api/tasks/{$task->id}/service-entry")->assertForbidden();
});
