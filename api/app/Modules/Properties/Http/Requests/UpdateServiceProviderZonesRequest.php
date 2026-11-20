<?php

namespace App\Modules\Properties\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Override;

class UpdateServiceProviderZonesRequest extends FormRequest
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
            // The full list of zones: missing ones are removed. An empty list removes all.
            'zone_ids' => ['present', 'array', 'max:50'],
            'zone_ids.*' => ['integer', 'distinct', Rule::exists('zones', 'id')->where('is_active', true)],
        ];
    }

    #[Override]
    public function messages(): array
    {
        return [
            'zone_ids.present' => 'Envie a lista de zonas (pode ser vazia).',
            'zone_ids.array' => 'A lista de zonas é inválida.',
            'zone_ids.max' => 'Escolha no máximo 50 zonas.',
            'zone_ids.*.integer' => 'Escolha uma zona válida.',
            'zone_ids.*.distinct' => 'A mesma zona foi escolhida mais de uma vez.',
            'zone_ids.*.exists' => 'A zona não existe ou está inativa.',
        ];
    }
}
