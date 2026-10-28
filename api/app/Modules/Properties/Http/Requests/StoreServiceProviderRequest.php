<?php

namespace App\Modules\Properties\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Override;

class StoreServiceProviderRequest extends FormRequest
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
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'phone' => ['required', 'regex:/^\+?\d{9,15}$/'],
            'address' => ['nullable', 'string', 'max:200'],
            'postal_code' => ['nullable', 'regex:/^\d{4}-\d{3}$/'],
            'city' => ['nullable', 'string', 'max:80'],
        ];
    }

    #[Override]
    public function messages(): array
    {
        return [
            'name.required' => 'Indique o nome.',
            'name.max' => 'O nome não pode ter mais de 120 caracteres.',
            'email.required' => 'Indique o e-mail.',
            'email.email' => 'Indique um e-mail válido.',
            'email.unique' => 'Já existe uma conta com este e-mail.',
            'password.required' => 'Indique a password inicial.',
            'password.min' => 'A password deve ter pelo menos 8 caracteres.',
            'phone.required' => 'Indique o telefone.',
            'phone.regex' => 'Indique um telefone válido, com 9 a 15 dígitos.',
            'address.max' => 'A morada não pode ter mais de 200 caracteres.',
            'postal_code.regex' => 'O código postal deve ter o formato 1234-567.',
            'city.max' => 'A localidade não pode ter mais de 80 caracteres.',
        ];
    }
}
