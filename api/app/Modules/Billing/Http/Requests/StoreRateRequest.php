<?php

namespace App\Modules\Billing\Http\Requests;

use App\Modules\Billing\Models\Rate;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Override;

class StoreRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin', 'supervisor') ?? false;
    }

    #[Override]
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('currency'))) {
            $this->merge(['currency' => strtoupper(trim($this->input('currency')))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Empty means a general rate for all properties.
            'property_id' => ['nullable', 'integer', Rule::exists('properties', 'id')],
            'task_type' => ['required', 'string', Rule::in(Rate::TASK_TYPES)],
            'amount' => ['required', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'currency' => ['nullable', 'string', 'regex:/^[A-Z]{3}$/'],
            'valid_from' => ['required', 'date_format:Y-m-d'],
        ];
    }

    #[Override]
    public function messages(): array
    {
        return [
            'property_id.integer' => 'Escolha um imóvel válido.',
            'property_id.exists' => 'O imóvel não existe.',
            'task_type.required' => 'Indique o tipo de tarefa.',
            'task_type.in' => 'O tipo de tarefa é inválido.',
            'amount.required' => 'Indique o valor.',
            'amount.numeric' => 'O valor deve ser um número.',
            'amount.min' => 'O valor não pode ser negativo.',
            'amount.max' => 'O valor é demasiado elevado.',
            'amount.decimal' => 'O valor pode ter no máximo 2 casas decimais.',
            'currency.regex' => 'A moeda deve ter 3 letras (ex.: EUR).',
            'valid_from.required' => 'Indique a data a partir da qual a tarifa se aplica.',
            'valid_from.date_format' => 'A data deve estar no formato AAAA-MM-DD.',
        ];
    }
}
