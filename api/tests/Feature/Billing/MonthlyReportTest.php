<?php

declare(strict_types=1);

use App\Models\Task;
use App\Modules\Billing\Models\Rate;
use App\Modules\Billing\Models\ServiceEntry;
use App\Modules\Billing\Services\RecordServiceEntry;
use App\Modules\Billing\Support\Cents;
use App\Modules\Properties\Models\Property;
use App\Modules\Properties\Models\ServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function reportProperty(string $name = 'Casa Relatório', string $timezone = 'Europe/Lisbon'): Property
{
    return Property::create([
        'name' => $name,
        'address' => 'Rua Fictícia 131',
        'postal_code' => '4050-123',
        'city' => 'Porto',
        'timezone' => $timezone,
    ]);
}

/** Completed task and its service entry; without an agreed amount the rate in force is used. */
function reportEntry(
    Property $property,
    ServiceProvider $provider,
    ?float $agreedAmount,
    string $scheduledStartAt = '2026-12-10 11:30:00',
): ServiceEntry {
    $task = Task::factory()->create([
        'property_id' => $property->id,
        'service_provider_id' => $provider->id,
        'type' => 'checkout_cleaning',
        'status' => 'completed',
        'started_at' => $scheduledStartAt,
        'completed_at' => $scheduledStartAt,
        'scheduled_start_at' => $scheduledStartAt,
        'agreed_amount' => $agreedAmount,
    ]);

    return app(RecordServiceEntry::class)->forTask($task->id);
}

/** @param  array<int, array<string, mixed>>  $rows */
function centsOf(array $rows, string $field): int
{
    return array_sum(array_map(fn (array $row) => Cents::from($row[$field]), $rows));
}

it('returns the settlement per provider with totals that match the detail', function () {
    authenticateApiUser(userWithRole('admin'));
    $property = reportProperty();
    $ana = createServiceProvider('ana.relatorio@example.test');
    $bruno = createServiceProvider('bruno.relatorio@example.test');

    $adjusted = reportEntry($property, $ana, 45.5);
    reportEntry($property, $ana, 30.1, '2026-12-15 10:00:00');
    reportEntry($property, $ana, 12.35, '2026-12-20 10:00:00');
    reportEntry($property, $bruno, 20.05);
    $adjusted->adjustments()->create(['amount' => '-5.25', 'reason' => 'Serviço incompleto']);
    $adjusted->adjustments()->create(['amount' => '0.10', 'reason' => 'Arredondamento']);

    $data = $this->getJson('/api/reports/monthly?month=2026-12')
        ->assertOk()
        ->assertJsonPath('data.month', '2026-12')
        ->assertJsonPath('data.period.from', '2026-12-01')
        ->assertJsonPath('data.period.to', '2026-12-31')
        ->assertJsonPath('data.status', 'apurado')
        ->assertJsonMissingPath('data.payment_status')
        ->json('data');

    expect($data['providers'])->toHaveCount(2)
        ->and($data['totals'])->toHaveCount(1)
        ->and($data['totals'][0]['services'])->toBe(4)
        ->and($data['totals'][0]['original_total'])->toBe('108.00')
        ->and($data['totals'][0]['adjustments_total'])->toBe('-5.15')
        ->and($data['totals'][0]['total'])->toBe('102.85');

    $anaSection = collect($data['providers'])->firstWhere('service_provider_id', $ana->id);

    expect($anaSection['name'])->toBe($ana->user->name)
        ->and($anaSection['entries'])->toHaveCount(3)
        ->and($anaSection['entries'][0]['amount'])->toBe('45.50')
        ->and($anaSection['entries'][0]['adjustments_total'])->toBe('-5.15')
        ->and($anaSection['entries'][0]['final_amount'])->toBe('40.35');

    // The detail reproduces every total, per provider and overall.
    $providerTotals = 0;
    foreach ($data['providers'] as $section) {
        expect(centsOf($section['entries'], 'final_amount'))->toBe(Cents::from($section['totals'][0]['total']))
            ->and(centsOf($section['entries'], 'amount'))->toBe(Cents::from($section['totals'][0]['original_total']))
            ->and(count($section['entries']))->toBe($section['totals'][0]['services']);
        $providerTotals += Cents::from($section['totals'][0]['total']);
    }

    expect($providerTotals)->toBe(Cents::from($data['totals'][0]['total']));
});

it('returns empty totals for a month without services', function () {
    authenticateApiUser(userWithRole('supervisor'));
    reportEntry(reportProperty(), createServiceProvider('vazio.relatorio@example.test'), 40);

    $this->getJson('/api/reports/monthly?month=2026-11')
        ->assertOk()
        ->assertJsonPath('data.totals', [])
        ->assertJsonPath('data.providers', []);
});

it('places services in the month of the property local date', function () {
    authenticateApiUser(userWithRole('admin'));
    $provider = createServiceProvider('fuso.relatorio@example.test');

    // 01:30 UTC on 1 December is still 30 November in São Paulo (UTC-3).
    reportEntry(reportProperty('Casa São Paulo', 'America/Sao_Paulo'), $provider, 50, '2026-12-01 01:30:00');
    // Lisbon is UTC+0 in winter, so 23:30 UTC on 31 December stays in December.
    reportEntry(reportProperty('Casa Lisboa'), $provider, 30, '2026-12-31 23:30:00');

    $this->getJson('/api/reports/monthly?month=2026-11')
        ->assertOk()
        ->assertJsonPath('data.totals.0.total', '50.00')
        ->assertJsonPath('data.providers.0.entries.0.service_date', '2026-11-30');

    $this->getJson('/api/reports/monthly?month=2026-12')
        ->assertOk()
        ->assertJsonPath('data.totals.0.total', '30.00')
        ->assertJsonPath('data.providers.0.entries.0.service_date', '2026-12-31');
});

it('keeps the rate applied at the time after the rate changes', function () {
    authenticateApiUser(userWithRole('admin'));
    $property = reportProperty();
    $provider = createServiceProvider('tarifa.relatorio@example.test');

    Rate::create(['task_type' => 'checkout_cleaning', 'amount' => '40.00', 'currency' => 'EUR', 'valid_from' => '2026-01-01']);
    reportEntry($property, $provider, null);

    // A later rate, even one valid for the same date, does not change entries already recorded.
    Rate::create(['task_type' => 'checkout_cleaning', 'amount' => '55.00', 'currency' => 'EUR', 'valid_from' => '2026-12-01']);

    $this->getJson('/api/reports/monthly?month=2026-12')
        ->assertOk()
        ->assertJsonPath('data.providers.0.entries.0.amount_source', 'rate')
        ->assertJsonPath('data.providers.0.entries.0.amount', '40.00')
        ->assertJsonPath('data.totals.0.total', '40.00');
});

it('never adds different currencies together', function () {
    authenticateApiUser(userWithRole('admin'));
    $provider = createServiceProvider('moedas.relatorio@example.test');
    $lisbon = reportProperty();
    $brazil = reportProperty('Casa Brasil', 'America/Sao_Paulo');

    Rate::create(['property_id' => $brazil->id, 'task_type' => 'checkout_cleaning', 'amount' => '200.00', 'currency' => 'BRL', 'valid_from' => '2026-01-01']);
    reportEntry($lisbon, $provider, 40);
    reportEntry($brazil, $provider, null);

    $totals = $this->getJson('/api/reports/monthly?month=2026-12')->assertOk()->json('data.totals');

    expect($totals)->toBe([
        ['currency' => 'BRL', 'services' => 1, 'original_total' => '200.00', 'adjustments_total' => '0.00', 'total' => '200.00'],
        ['currency' => 'EUR', 'services' => 1, 'original_total' => '40.00', 'adjustments_total' => '0.00', 'total' => '40.00'],
    ]);
});

it('filters the report by provider', function () {
    authenticateApiUser(userWithRole('admin'));
    $property = reportProperty();
    $ana = createServiceProvider('filtro.ana@example.test');
    $bruno = createServiceProvider('filtro.bruno@example.test');
    reportEntry($property, $ana, 40);
    reportEntry($property, $bruno, 25);

    $this->getJson("/api/reports/monthly?month=2026-12&service_provider_id={$bruno->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data.providers')
        ->assertJsonPath('data.providers.0.service_provider_id', $bruno->id)
        ->assertJsonPath('data.totals.0.total', '25.00');
});

it('defaults to the current month and validates the filters', function () {
    authenticateApiUser(userWithRole('admin'));

    $this->getJson('/api/reports/monthly')
        ->assertOk()
        ->assertJsonPath('data.month', now()->format('Y-m'));

    $this->getJson('/api/reports/monthly?month=2026-13&service_provider_id=999999')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['month', 'service_provider_id']);
});

it('is only available to admin and supervisor', function () {
    authenticateApiUser(createServiceProvider('acesso.relatorio@example.test')->user);

    $this->getJson('/api/reports/monthly')->assertForbidden();
    $this->get('/api/reports/monthly/export')->assertForbidden();
});

it('exports the report as an Excel-friendly CSV', function () {
    authenticateApiUser(userWithRole('admin'));
    $provider = createServiceProvider('csv.relatorio@example.test');
    $entry = reportEntry(reportProperty('Casa "Azul"; Foz'), $provider, 45.5);
    $entry->adjustments()->create(['amount' => '-5.25', 'reason' => 'Serviço incompleto']);

    $response = $this->get('/api/reports/monthly/export?month=2026-12')->assertOk();

    expect($response->headers->get('content-type'))->toContain('text/csv')
        ->and($response->headers->get('content-disposition'))->toContain('relatorio-mensal-2026-12.csv');

    $content = $response->getContent();
    $lines = array_values(array_filter(explode("\n", substr($content, 3))));
    $name = $provider->user->name;

    expect(substr($content, 0, 3))->toBe("\xEF\xBB\xBF")
        ->and($lines)->toHaveCount(4)
        ->and($lines[0])->toStartWith('Prestador;Data;Imóvel;')
        ->and(str_getcsv($lines[1], ';', '"', ''))->toBe([
            $name, '10/12/2026', 'Casa "Azul"; Foz', 'Limpeza de checkout', 'Valor acordado na tarefa',
            '45,50', '-5,25', '40,25', 'EUR', 'apurado',
        ])
        ->and(str_getcsv($lines[2], ';', '"', '')[0])->toBe('Total '.$name)
        ->and(str_getcsv($lines[3], ';', '"', ''))->toBe([
            'Total geral', '', '', '1 serviço', '', '45,50', '-5,25', '40,25', 'EUR', 'apurado',
        ]);
});

it('exports only the header for an empty month', function () {
    authenticateApiUser(userWithRole('admin'));

    $content = $this->get('/api/reports/monthly/export?month=2026-11')->assertOk()->getContent();

    expect(array_values(array_filter(explode("\n", substr($content, 3)))))->toHaveCount(1);
});
