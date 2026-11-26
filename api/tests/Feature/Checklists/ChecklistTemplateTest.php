<?php

declare(strict_types=1);

use App\Modules\Checklists\Models\ChecklistTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** @param  array<int, array<string, mixed>>  $items */
function checklistTemplatePayload(string $taskType = 'checkout_cleaning', string $name = 'Limpeza', array $items = []): array
{
    return [
        'task_type' => $taskType,
        'name' => $name,
        'items' => $items !== [] ? $items : [
            ['label' => 'Fazer as camas'],
            ['label' => 'Repor amenities', 'required' => false],
        ],
    ];
}

it('publishes the first version of a checklist with ordered items', function (string $role) {
    $user = authenticateApiUser(userWithRole($role));

    $this->postJson('/api/checklist-templates', checklistTemplatePayload(name: '  Limpeza  ', items: [
        ['label' => ' Fazer as camas '],
        ['label' => 'Repor amenities', 'required' => false],
    ]))
        ->assertCreated()
        ->assertJsonPath('data.task_type', 'checkout_cleaning')
        ->assertJsonPath('data.version', 1)
        ->assertJsonPath('data.name', 'Limpeza')
        ->assertJsonPath('data.is_current', true)
        ->assertJsonPath('data.created_by_user_id', $user->id)
        ->assertJsonCount(2, 'data.items')
        ->assertJsonPath('data.items.0.position', 1)
        ->assertJsonPath('data.items.0.label', 'Fazer as camas')
        ->assertJsonPath('data.items.0.required', true)
        ->assertJsonPath('data.items.1.position', 2)
        ->assertJsonPath('data.items.1.required', false);
})->with(['admin', 'supervisor']);

it('creates a new version instead of changing the previous one', function () {
    authenticateApiUser(userWithRole('admin'));

    $first = $this->postJson('/api/checklist-templates', checklistTemplatePayload())->assertCreated();

    $this->postJson('/api/checklist-templates', checklistTemplatePayload(name: 'Limpeza v2', items: [
        ['label' => 'Aspirar'],
    ]))
        ->assertCreated()
        ->assertJsonPath('data.version', 2);

    $this->getJson('/api/checklist-templates/'.$first->json('data.id'))
        ->assertOk()
        ->assertJsonPath('data.version', 1)
        ->assertJsonPath('data.name', 'Limpeza')
        ->assertJsonPath('data.is_current', false)
        ->assertJsonCount(2, 'data.items');
});

it('keeps versions separate per task type', function () {
    authenticateApiUser(userWithRole('admin'));

    $this->postJson('/api/checklist-templates', checklistTemplatePayload())->assertCreated();

    $this->postJson('/api/checklist-templates', checklistTemplatePayload('checkin_inspection', 'Inspeção'))
        ->assertCreated()
        ->assertJsonPath('data.version', 1);
});

it('lists versions newest first and filters by task type and current version', function () {
    authenticateApiUser(userWithRole('supervisor'));

    $this->postJson('/api/checklist-templates', checklistTemplatePayload())->assertCreated();
    $this->postJson('/api/checklist-templates', checklistTemplatePayload(name: 'Limpeza v2'))->assertCreated();
    $this->postJson('/api/checklist-templates', checklistTemplatePayload('checkin_inspection', 'Inspeção'))->assertCreated();

    $this->getJson('/api/checklist-templates?task_type=checkout_cleaning')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.version', 2)
        ->assertJsonPath('data.0.is_current', true)
        ->assertJsonPath('data.0.items_count', 2)
        ->assertJsonPath('data.1.version', 1)
        ->assertJsonPath('data.1.is_current', false);

    $current = $this->getJson('/api/checklist-templates?current=1')->assertOk()->json('data');

    expect($current)->toHaveCount(2)
        ->and(collect($current)->pluck('is_current')->unique()->all())->toBe([true])
        ->and(collect($current)->firstWhere('task_type', 'checkout_cleaning')['version'])->toBe(2);
});

it('validates the checklist', function (array $payload, array $errors) {
    authenticateApiUser(userWithRole('admin'));

    $this->postJson('/api/checklist-templates', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($errors);

    expect(ChecklistTemplate::count())->toBe(0);
})->with([
    'unknown task type' => [['task_type' => 'deep_cleaning', 'name' => 'X', 'items' => [['label' => 'A']]], ['task_type']],
    'missing name' => [['task_type' => 'checkout_cleaning', 'items' => [['label' => 'A']]], ['name']],
    'no items' => [['task_type' => 'checkout_cleaning', 'name' => 'X', 'items' => []], ['items']],
    'empty label' => [['task_type' => 'checkout_cleaning', 'name' => 'X', 'items' => [['label' => '  ']]], ['items.0.label']],
    'repeated labels' => [['task_type' => 'checkout_cleaning', 'name' => 'X', 'items' => [['label' => 'Lixo'], ['label' => 'lixo']]], ['items.0.label']],
    'invalid required' => [['task_type' => 'checkout_cleaning', 'name' => 'X', 'items' => [['label' => 'A', 'required' => 'talvez']]], ['items.0.required']],
]);

it('forbids providers from managing checklists', function () {
    authenticateApiUser(createServiceProvider('checklist-provider@example.test')->user);

    $this->getJson('/api/checklist-templates')->assertForbidden();
    $this->postJson('/api/checklist-templates', checklistTemplatePayload())->assertForbidden();
});

it('requires authentication', function () {
    $this->getJson('/api/checklist-templates')->assertUnauthorized();
});
