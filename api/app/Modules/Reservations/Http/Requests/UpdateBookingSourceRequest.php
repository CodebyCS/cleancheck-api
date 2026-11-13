<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Http\Requests;

use App\Modules\Reservations\Models\BookingSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class UpdateBookingSourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin', 'supervisor') ?? false;
    }

    public function rules(): array
    {
        $source = $this->bookingSource();

        return [
            'url' => [
                'bail',
                'required_without:is_active',
                'string',
                'max:2048',
                'url:https',
                Rule::unique('booking_sources', 'url')
                    ->where('property_id', $source->property_id)
                    ->ignore($source->id),
            ],
            'is_active' => ['required_without:url', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->bookingSource()->type !== 'url') {
                    $validator->errors()->add('source', 'Só fontes URL podem ser editadas.');
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'url.required_without' => 'Indique o URL do calendário ou o estado da fonte.',
            'url.max' => 'O URL é demasiado longo.',
            'url.url' => 'O URL tem de ser um endereço https:// válido.',
            'url.unique' => 'Este calendário já está registado para este imóvel.',
            'is_active.required_without' => 'Indique o URL do calendário ou o estado da fonte.',
            'is_active.boolean' => 'O estado da fonte tem de ser verdadeiro ou falso.',
        ];
    }

    private function bookingSource(): BookingSource
    {
        return $this->route('bookingSource');
    }
}