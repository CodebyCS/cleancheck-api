<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StorePropertyRequest extends FormRequest
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
            'zone_id'                         => ['required', 'integer', 'exists:zones,id'],
            'responsible_service_provider_id' => ['nullable', 'integer', 'exists:service_providers,id'],
            'name'                            => ['required', 'string', 'max:255'],
            'address'                         => ['required', 'string', 'max:255'],
            'latitude'                        => ['nullable', 'numeric', 'between:-90,90'],
            'longitude'                       => ['nullable', 'numeric', 'between:-180,180'],
            'timezone'                        => ['sometimes', 'timezone'],
            'default_check_in_time'           => ['required', 'date_format:H:i'],
            'default_check_out_time'          => ['required', 'date_format:H:i'],
            'base_cleaning_amount'            => ['required', 'numeric', 'min:0'],
            'is_active'                       => ['sometimes', 'boolean'],
        ];
    }
}
