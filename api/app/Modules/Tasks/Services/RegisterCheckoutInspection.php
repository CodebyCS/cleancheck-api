<?php

namespace App\Modules\Tasks\Services;

use App\Models\Task;
use App\Models\TaskCheckoutInspection;
use App\Models\TaskEvidence;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class RegisterCheckoutInspection
{
    public function execute(int $taskId, User $user, array $data): array
    {
        return DB::transaction(function () use ($taskId, $user, $data): array {
            $providerId = $user->serviceProvider?->id;
            abort_if($providerId === null, 403);
            $task = Task::query()->whereKey($taskId)->where('service_provider_id', $providerId)
                ->with('booking.bookingSource.property')->lockForUpdate()->firstOrFail();
            abort_unless($task->type === 'checkout_cleaning', 422, 'A vistoria de saída só se aplica à tarefa de limpeza de checkout.');

            $booking = $task->booking;
            $property = $booking?->bookingSource?->property;
            abort_if($booking === null || $property === null, 422, 'A tarefa não tem reserva e imóvel válidos.');
            $checkout = $booking->overridden_check_out_at ?? $booking->source_check_out_at;
            abort_if($checkout === null, 422, 'A reserva não tem data de checkout.');
            $timezone = $property->timezone ?: 'Europe/Lisbon';
            $checkoutAt = CarbonImmutable::parse(
                $checkout->format('Y-m-d').' '.($property->default_check_out_time ?: '11:00'),
                $timezone,
            );
            abort_if(now()->lt($checkoutAt), 422, 'A vistoria de saída só pode ser registada após a hora prevista de checkout.');

            $responses = array_map(fn (array $item): array => ['key' => $item['key'], 'value' => $item['value']], $data['responses']);
            usort($responses, fn (array $a, array $b): int => strcmp($a['key'], $b['key']));
            $evidenceIds = array_map('intval', $data['evidence_ids'] ?? []);
            sort($evidenceIds);
            $payload = ['responses' => $responses, 'observations' => $data['observations'] ?? null, 'evidence_ids' => $evidenceIds];
            $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));

            $existing = TaskCheckoutInspection::query()->where('task_id', $taskId)
                ->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing !== null) {
                abort_unless(hash_equals($existing->payload_hash, $hash), 409,
                    'A chave de idempotência já foi usada com dados diferentes.');
                return ['record' => $existing, 'created' => false];
            }
            abort_if(TaskCheckoutInspection::query()->where('task_id', $taskId)->exists(), 409,
                'A vistoria de saída desta tarefa já foi registada.');

            $evidences = TaskEvidence::query()->whereIn('id', $evidenceIds)->where('task_id', $taskId)
                ->where('uploaded_by_user_id', $user->id)->lockForUpdate()->get();
            if ($evidences->count() !== count($evidenceIds)) {
                throw ValidationException::withMessages(['evidence_ids' => 'Os anexos devem pertencer a esta tarefa e ao autor autenticado.']);
            }
            foreach ($evidences as $evidence) {
                if ($evidence->disk !== 'task_evidence' || ! Storage::disk('task_evidence')->exists($evidence->path)) {
                    throw ValidationException::withMessages(['evidence_ids' => 'Um dos anexos não está disponível.']);
                }
            }

            $inspection = TaskCheckoutInspection::query()->create([
                'task_id' => $task->id, 'user_id' => $user->id,
                'idempotency_key' => $data['idempotency_key'], 'payload_hash' => $hash,
                'responses' => $responses, 'observations' => $payload['observations'], 'recorded_at' => now(),
            ]);
            $inspection->evidences()->attach($evidenceIds);

            return ['record' => $inspection, 'created' => true];
        });
    }
}
