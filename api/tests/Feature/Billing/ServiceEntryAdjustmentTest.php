<?php

declare(strict_types=1);

use App\Models\Task;
use App\Modules\Billing\Models\ServiceEntry;
use App\Modules\Billing\Models\ServiceEntryAdjustment;
use App\Modules\Billing\Services\RecordServiceEntry;
use App\Modules\Properties\Models\Property;
use App\Modules\Properties\Models\ServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function adjustmentProperty(string $name = 'Casa Ajustes'): Property
{
    return Property::create([
        'name' => $name,
        'address' => 'Rua Fictícia 61',
        'postal_code' => '4050-123',
        'city' => 'Porto',
    ]);
}

/** Completed task with an agreed amount and its single service entry. */
function adjustableEntry(
    Property $property,
    ServiceProvider $provider,
    float $amount,
    string $scheduledStartAt = '2026-12-10 11:30:00',
): ServiceEntry {
    $task = Task::factory()->create([
        'property_id' => $property->id,
        'service_provider_id' => $provider->id,
        'status' => 'completed',
        'started_at' => '2026-12-10 11:00:00',
        'completed_at' => '2026-12-10 14:00:00',
        'scheduled_start_at' => $scheduledStartAt,
        'agreed_amount' => $amount,
    ]);

    return app(RecordServiceEntry::class)->forTask($task->id);
}

// ---------------------------------------------------------------------------
// Recording adjustments
// ---------------------------------------------------------------------------

it('records an adjustment without changing the original entry', function (string $role) {
    $user = authenticateApiUser(userWithRole($role));
    $entry = adjustableEntry(adjustmentProperty(), createServiceProvider('ajuste@example.test'), 45.5);

    $this->postJson("/api/service-entries/{$entry->id}/adjustments", [
        'amount' => -5.25,
        'reason' => '  Serviço incompleto  ',
    ])
        ->assertCreated()
        ->assertJsonPath('data.service_entry_id', $entry->id)
        ->assertJsonPath('data.amount', '-5.25')
        ->assertJsonPath('data.reason', 'Serviço incompleto')
        ->assertJsonPath('data.created_by.id', $user->id)
        ->assertJsonPath('meta.currency', 'EUR')
        ->assertJsonPath('meta.original_amount', '45.50')
        ->assertJsonPath('meta.adjustments_total', '-5.25')
        ->assertJsonPath('meta.final_amount', '40.25');

    expect($entry->fresh()->amount)->toBe('45.50')
        ->and(ServiceEntry::query()->where('task_id', $entry->task_id)->count())->toBe(1);
})->with(['admin', 'supervisor']);

it('adds up several adjustments in cents', function () {
    authenticateApiUser(userWithRole('admin'));
    $entry = adjustableEntry(adjustmentProperty(), createServiceProvider('soma@example.test'), 30);

    foreach (['0.10', '0.20', '-0.05'] as $amount) {
        $this->postJson("/api/service-entries/{$entry->id}/adjustments", ['amount' => $amount, 'reason' => 'Correção'])
            ->assertCreated();
    }

    expect($entry->fresh()->adjustmentsTotal())->toBe('0.25')
        ->and($entry->fresh()->finalAmount())->toBe('30.25');
});

it('allows the final amount to reach zero but not below', function () {
    authenticateApiUser(userWithRole('admin'));
    $entry = adjustableEntry(adjustmentProperty(), createServiceProvider('zero@example.test'), 20);

    $this->postJson("/api/service-entries/{$entry->id}/adjustments", ['amount' => -20.01, 'reason' => 'Anular'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('amount');

    $this->postJson("/api/service-entries/{$entry->id}/adjustments", ['amount' => -20, 'reason' => 'Serviço não feito'])
        ->assertCreated()
        ->assertJsonPath('meta.final_amount', '0.00');

    $this->postJson("/api/service-entries/{$entry->id}/adjustments", ['amount' => -0.01, 'reason' => 'Mais'])
        ->assertUnprocessable();

    expect(ServiceEntryAdjustment::count())->toBe(1);
});

it('validates the adjustment', function (array $payload, array $errors) {
    authenticateApiUser(userWithRole('admin'));
    $entry = adjustableEntry(adjustmentProperty(), createServiceProvider('validar@example.test'), 20);

    $this->postJson("/api/service-entries/{$entry->id}/adjustments", $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($errors);

    expect(ServiceEntryAdjustment::count())->toBe(0);
})->with([
    'missing fields' => [[], ['amount', 'reason']],
    'zero amount' => [['amount' => 0, 'reason' => 'X'], ['amount']],
    'not a number' => [['amount' => 'dez', 'reason' => 'X'], ['amount']],
    'three decimals' => [['amount' => 1.234, 'reason' => 'X'], ['amount']],
    'blank reason' => [['amount' => 1, 'reason' => '   '], ['reason']],
    'reason too long' => [['amount' => 1, 'reason' => str_repeat('a', 501)], ['reason']],
]);

it('returns 404 for an unknown entry', function () {
    authenticateApiUser(userWithRole('admin'));

    $this->postJson('/api/service-entries/999999/adjustments', ['amount' => 1, 'reason' => 'X'])->assertNotFound();
    $this->getJson('/api/service-entries/999999')->assertNotFound();
});

it('forbids providers from adjusting or reading the review', function () {
    $provider = createServiceProvider('proibido@example.test');
    $entry = adjustableEntry(adjustmentProperty(), $provider, 20);

    authenticateApiUser($provider->user);

    $this->postJson("/api/service-entries/{$entry->id}/adjustments", ['amount' => 1, 'reason' => 'X'])->assertForbidden();
    $this->getJson("/api/service-entries/{$entry->id}")->assertForbidden();
});

// ---------------------------------------------------------------------------
// Review list and detail
// ---------------------------------------------------------------------------

it('shows the adjustment history and final amount in the review detail', function () {
    $admin = authenticateApiUser(userWithRole('admin'));
    $entry = adjustableEntry(adjustmentProperty(), createServiceProvider('detalhe@example.test'), 45.5);

    $this->postJson("/api/service-entries/{$entry->id}/adjustments", ['amount' => -5.25, 'reason' => 'Atraso'])->assertCreated();
    $this->postJson("/api/service-entries/{$entry->id}/adjustments", ['amount' => 2, 'reason' => 'Extra'])->assertCreated();

    $this->getJson("/api/service-entries/{$entry->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $entry->id)
        ->assertJsonPath('data.property_name', 'Casa Ajustes')
        ->assertJsonPath('data.amount', '45.50')
        ->assertJsonPath('data.adjustments_total', '-3.25')
        ->assertJsonPath('data.final_amount', '42.25')
        ->assertJsonCount(2, 'data.adjustments')
        ->assertJsonPath('data.adjustments.0.reason', 'Atraso')
        ->assertJsonPath('data.adjustments.0.amount', '-5.25')
        ->assertJsonPath('data.adjustments.1.reason', 'Extra')
        ->assertJsonPath('data.adjustments.1.created_by.name', $admin->name);
});

it('uses final amounts in the review list and monthly totals', function () {
    authenticateApiUser(userWithRole('supervisor'));
    $property = adjustmentProperty();
    $provider = createServiceProvider('totais@example.test');
    $adjusted = adjustableEntry($property, $provider, 45.5);
    adjustableEntry($property, $provider, 30);

    $this->postJson("/api/service-entries/{$adjusted->id}/adjustments", ['amount' => -5.25, 'reason' => 'Atraso'])->assertCreated();

    $response = $this->getJson('/api/service-entries?month=2026-12')
        ->assertOk()
        ->assertJsonPath('meta.totals.0.currency', 'EUR')
        ->assertJsonPath('meta.totals.0.entries', 2)
        ->assertJsonPath('meta.totals.0.original_total', '75.50')
        ->assertJsonPath('meta.totals.0.adjustments_total', '-5.25')
        ->assertJsonPath('meta.totals.0.total', '70.25');

    $row = collect($response->json('data'))->firstWhere('id', $adjusted->id);

    expect($row['amount'])->toBe('45.50')
        ->and($row['adjustments_total'])->toBe('-5.25')
        ->and($row['final_amount'])->toBe('40.25')
        ->and($row)->not->toHaveKey('adjustments');
});

it('keeps the final amount when the entry creation is retried', function () {
    authenticateApiUser(userWithRole('admin'));
    $entry = adjustableEntry(adjustmentProperty(), createServiceProvider('repetir@example.test'), 45.5);

    $this->postJson("/api/service-entries/{$entry->id}/adjustments", ['amount' => -5.5, 'reason' => 'Atraso'])->assertCreated();

    $this->postJson("/api/tasks/{$entry->task_id}/service-entry")
        ->assertOk()
        ->assertJsonPath('data.amount', '45.50')
        ->assertJsonPath('data.final_amount', '40.00');
});

// ---------------------------------------------------------------------------
// Provider monthly summary
// ---------------------------------------------------------------------------

it('gives the provider a monthly summary of their own entries only', function () {
    $property = adjustmentProperty();
    $provider = createServiceProvider('resumo@example.test');
    $other = createServiceProvider('outro@example.test');

    authenticateApiUser(userWithRole('admin'));
    $adjusted = adjustableEntry($property, $provider, 45.5);
    adjustableEntry($property, $provider, 30, '2026-12-31 20:00:00');
    adjustableEntry($property, $provider, 10, '2027-01-02 10:00:00');
    adjustableEntry($property, $other, 99);
    $this->postJson("/api/service-entries/{$adjusted->id}/adjustments", ['amount' => -5.25, 'reason' => 'Atraso'])->assertCreated();

    authenticateApiUser($provider->user);

    $this->getJson('/api/my/service-entries/summary?month=2026-12')
        ->assertOk()
        ->assertJsonPath('data.month', '2026-12')
        ->assertJsonCount(2, 'data.entries')
        ->assertJsonPath('data.totals.0.entries', 2)
        ->assertJsonPath('data.totals.0.original_total', '75.50')
        ->assertJsonPath('data.totals.0.adjustments_total', '-5.25')
        ->assertJsonPath('data.totals.0.total', '70.25')
        ->assertJsonPath('data.entries.0.id', $adjusted->id)
        ->assertJsonPath('data.entries.0.property_name', 'Casa Ajustes')
        ->assertJsonPath('data.entries.0.final_amount', '40.25')
        ->assertJsonPath('data.entries.0.adjustments.0.amount', '-5.25')
        ->assertJsonPath('data.entries.0.adjustments.0.reason', 'Atraso')
        ->assertJsonMissingPath('data.entries.0.adjustments.0.created_by')
        ->assertJsonPath('data.entries.1.service_date', '2026-12-31');

    $this->getJson('/api/my/service-entries/summary?month=2027-01')
        ->assertOk()
        ->assertJsonCount(1, 'data.entries');
});

it('defaults the summary to the current month and returns empty totals', function () {
    authenticateApiUser(createServiceProvider('vazio@example.test')->user);

    $this->getJson('/api/my/service-entries/summary')
        ->assertOk()
        ->assertJsonPath('data.month', now()->format('Y-m'))
        ->assertJsonPath('data.totals', [])
        ->assertJsonPath('data.entries', []);
});

it('validates the summary month and limits it to providers', function () {
    authenticateApiUser(createServiceProvider('mes@example.test')->user);

    $this->getJson('/api/my/service-entries/summary?month=2026-13')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('month');

    authenticateApiUser(userWithRole('admin'));

    $this->getJson('/api/my/service-entries/summary')->assertForbidden();
});
