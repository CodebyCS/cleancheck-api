<?php

namespace App\Modules\Properties\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Override;

class StoreZoneRequest extends FormRequest
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
            // On update the current zone is ignored, so it can keep its own name.
            'name' => ['required', 'string', 'max:80', Rule::unique('zones', 'name')->ignore($this->route('zone'))],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    #[Override]
    public function messages(): array
    {
        return [
            'name.required' => 'Indique o nome da zona.',
            'name.max' => 'O nome da zona não pode ter mais de 80 caracteres.',
            'name.unique' => 'Já existe uma zona com este nome.',
            'description.max' => 'A descrição não pode ter mais de 1000 caracteres.',
        ];
    }
}
