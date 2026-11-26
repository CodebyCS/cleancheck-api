<?php

namespace App\Modules\Checklists\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Override;

class MarkChecklistItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('service_provider') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'done' => ['required', 'boolean'],
        ];
    }

    #[Override]
    public function messages(): array
    {
        return [
            'done.required' => 'Indique se o item foi feito.',
            'done.boolean' => 'O valor de feito tem de ser verdadeiro ou falso.',
        ];
    }
}
