<?php

namespace App\Modules\Billing\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Override;

class ListServiceEntriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin', 'supervisor') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'month' => ['sometimes', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'service_provider_id' => ['sometimes', 'integer'],
            'property_id' => ['sometimes', 'integer'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    #[Override]
    public function messages(): array
    {
        return [
            'month.regex' => 'O mês deve estar no formato AAAA-MM.',
            'service_provider_id.integer' => 'Escolha um prestador válido.',
            'property_id.integer' => 'Escolha um imóvel válido.',
            'per_page.max' => 'Pode pedir no máximo 100 lançamentos por página.',
        ];
    }
}
