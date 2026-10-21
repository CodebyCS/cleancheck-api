<?php

namespace App\Modules\Properties\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Override;

class StorePropertyRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin', 'supervisor') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'address' => ['required', 'string', 'max:200'],
            'postal_code' => ['required', 'regex:/^\d{4}-\d{3}$/'],
            'city' => ['required', 'string', 'max:80'],
            'access_instructions' => ['nullable', 'string', 'max:1000'],
        ];
    }

    #[Override]
    public function messages(): array
    {
        return [
            'name.required' => 'Indique o nome do imóvel.',
            'name.max' => 'O nome não pode ter mais de 120 caracteres.',
            'address.required' => 'Indique a morada.',
            'address.max' => 'A morada não pode ter mais de 200 caracteres.',
            'postal_code.required' => 'Indique o código postal.',
            'postal_code.regex' => 'O código postal deve ter o formato 1234-567.',
            'city.required' => 'Indique a localidade.',
            'city.max' => 'A localidade não pode ter mais de 80 caracteres.',
            'access_instructions.max' => 'As instruções de acesso não podem ter mais de 1000 caracteres.',
        ];
    }
}
