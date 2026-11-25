<?php

namespace App\Modules\Checklists\Http\Requests;

use App\Modules\Checklists\Models\ChecklistTemplate;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Override;

class StoreChecklistTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin', 'supervisor') ?? false;
    }

    #[Override]
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }

        if (is_array($this->input('items'))) {
            $this->merge([
                'items' => array_map(
                    fn ($item) => is_array($item) && is_string($item['label'] ?? null)
                        ? [...$item, 'label' => trim($item['label'])]
                        : $item,
                    $this->input('items'),
                ),
            ]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'task_type' => ['required', 'string', Rule::in(ChecklistTemplate::TASK_TYPES)],
            'name' => ['required', 'string', 'max:120'],
            // Order in the array is the order shown to the provider.
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*' => ['array:label,required'],
            'items.*.label' => ['required', 'string', 'max:200', 'distinct:ignore_case'],
            'items.*.required' => ['sometimes', 'boolean'],
        ];
    }

    #[Override]
    public function messages(): array
    {
        return [
            'task_type.required' => 'Indique o tipo de tarefa.',
            'task_type.in' => 'O tipo de tarefa é inválido.',
            'name.required' => 'Indique o nome da checklist.',
            'name.max' => 'O nome pode ter no máximo 120 caracteres.',
            'items.required' => 'A checklist tem de ter pelo menos um item.',
            'items.array' => 'Os itens são inválidos.',
            'items.min' => 'A checklist tem de ter pelo menos um item.',
            'items.max' => 'A checklist pode ter no máximo 100 itens.',
            'items.*.array' => 'Cada item só pode ter descrição e obrigatoriedade.',
            'items.*.label.required' => 'Todos os itens precisam de uma descrição.',
            'items.*.label.max' => 'A descrição de um item pode ter no máximo 200 caracteres.',
            'items.*.label.distinct' => 'Existem itens repetidos.',
            'items.*.required.boolean' => 'A obrigatoriedade de um item tem de ser verdadeiro ou falso.',
        ];
    }
}
