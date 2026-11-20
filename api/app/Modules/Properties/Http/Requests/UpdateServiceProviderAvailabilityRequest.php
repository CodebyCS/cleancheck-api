<?php

namespace App\Modules\Properties\Http\Requests;

use App\Modules\Properties\Http\Requests\Concerns\AuthorizesServiceProviderSchedule;
use App\Modules\Properties\Models\ServiceProviderAvailability;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Override;

class UpdateServiceProviderAvailabilityRequest extends FormRequest
{
    use AuthorizesServiceProviderSchedule;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // The full weekly schedule: it replaces the current one. An empty list clears it.
            'intervals' => ['present', 'array', 'max:50'],
            'intervals.*.weekday' => [
                'required',
                'integer',
                'between:'.ServiceProviderAvailability::MONDAY.','.ServiceProviderAvailability::SUNDAY,
            ],
            'intervals.*.start_time' => ['required', 'date_format:H:i'],
            'intervals.*.end_time' => ['required', 'date_format:H:i'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $intervals = collect($this->input('intervals'))
                    ->map(fn (array $interval, int $index) => [...$interval, 'index' => $index]);

                // HH:MM strings compare correctly as text.
                foreach ($intervals as $interval) {
                    if ($interval['end_time'] <= $interval['start_time']) {
                        $validator->errors()->add(
                            "intervals.{$interval['index']}.end_time",
                            'A hora de fim tem de ser depois da hora de início.',
                        );
                    }
                }

                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                // Intervals of the same day cannot overlap; touching ends (12:00 / 12:00) are allowed.
                foreach ($intervals->groupBy('weekday') as $sameDay) {
                    $latestEnd = null;

                    foreach ($sameDay->sortBy('start_time') as $interval) {
                        if ($latestEnd !== null && $interval['start_time'] < $latestEnd) {
                            $validator->errors()->add(
                                "intervals.{$interval['index']}.start_time",
                                'Este intervalo sobrepõe-se a outro do mesmo dia.',
                            );
                        }

                        $latestEnd = max($latestEnd ?? '', $interval['end_time']);
                    }
                }
            },
        ];
    }

    #[Override]
    public function messages(): array
    {
        return [
            'intervals.present' => 'Envie a lista de horários (pode ser vazia).',
            'intervals.array' => 'A lista de horários é inválida.',
            'intervals.max' => 'Indique no máximo 50 intervalos.',
            'intervals.*.weekday.required' => 'Indique o dia da semana.',
            'intervals.*.weekday.integer' => 'O dia da semana é inválido.',
            'intervals.*.weekday.between' => 'O dia da semana tem de ser de 1 (segunda) a 7 (domingo).',
            'intervals.*.start_time.required' => 'Indique a hora de início.',
            'intervals.*.start_time.date_format' => 'A hora de início tem de estar no formato HH:MM.',
            'intervals.*.end_time.required' => 'Indique a hora de fim.',
            'intervals.*.end_time.date_format' => 'A hora de fim tem de estar no formato HH:MM.',
        ];
    }
}
