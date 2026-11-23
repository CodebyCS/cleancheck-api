<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreBookingSourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin', 'supervisor') ?? false;
    }

    public function rules(): array
    {
        return [
            'url' => [
                'bail',
                'required',
                'string',
                'max:2048',
                'url:https',
                Rule::unique('booking_sources', 'url')
                    ->where('property_id', $this->route('property')->id),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'url.required' => 'Indique o URL do calendário.',
            'url.max' => 'O URL é demasiado longo.',
            'url.url' => 'O URL tem de ser um endereço https:// válido.',
            'url.unique' => 'Este calendário já está registado para este imóvel.',
        ];
    }
}