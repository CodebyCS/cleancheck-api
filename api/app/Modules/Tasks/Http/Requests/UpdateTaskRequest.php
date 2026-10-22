<?php

namespace App\Modules\Tasks\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UpdateTaskRequest extends FormRequest
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
            'service_provider_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('service_providers', 'id')->where(
                    fn ($query) => $query->whereIn('user_id', DB::table('users')->where('is_active', true)->select('id')),
                ),
            ],
            'agreed_amount'       => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'notes'               => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
