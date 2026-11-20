<?php

namespace App\Modules\Properties\Http\Requests;

use App\Modules\Properties\Http\Requests\Concerns\AuthorizesServiceProviderSchedule;
use App\Modules\Properties\Models\ServiceProvider;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;
use Override;

class StoreServiceProviderUnavailabilityRequest extends FormRequest
{
    use AuthorizesServiceProviderSchedule;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // ISO-8601 with offset (e.g. 2026-12-24T00:00:00+00:00); stored in UTC.
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'reason' => ['nullable', 'string', 'max:120'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                /** @var ServiceProvider $serviceProvider */
                $serviceProvider = $this->route('serviceProvider');

                $overlaps = $serviceProvider->unavailabilities()
                    ->where('starts_at', '<', $this->endsAt())
                    ->where('ends_at', '>', $this->startsAt())
                    ->exists();

                if ($overlaps) {
                    $validator->errors()->add('starts_at', 'Já existe uma indisponibilidade neste período.');
                }
            },
        ];
    }

    public function startsAt(): Carbon
    {
        return Carbon::parse($this->input('starts_at'))->utc();
    }

    public function endsAt(): Carbon
    {
        return Carbon::parse($this->input('ends_at'))->utc();
    }

    #[Override]
    public function messages(): array
    {
        return [
            'starts_at.required' => 'Indique o início da indisponibilidade.',
            'starts_at.date' => 'O início da indisponibilidade é inválido.',
            'ends_at.required' => 'Indique o fim da indisponibilidade.',
            'ends_at.date' => 'O fim da indisponibilidade é inválido.',
            'ends_at.after' => 'O fim tem de ser depois do início.',
            'reason.max' => 'O motivo não pode ter mais de 120 caracteres.',
        ];
    }
}
