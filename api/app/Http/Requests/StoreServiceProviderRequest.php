<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreServiceProviderRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // tabela users
            'name'           => ['required', 'string', 'max:255'],
            'email'          => ['required', 'email', 'max:255', 'unique:users,email'],
            'password'       => ['required', 'string', 'min:8'],
            'phone'          => ['nullable', 'string', 'max:20'],
            'address'        => ['nullable', 'string', 'max:255'],
            // tabela service_providers
            'base_latitude'  => ['nullable', 'numeric', 'between:-90,90'],
            'base_longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ];
    }
}
