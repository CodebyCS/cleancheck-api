<?php

namespace App\Modules\Tasks\Services;

use App\Models\Task;
use App\Models\TaskEvidence;
use App\Models\TaskExecution;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class RegisterTaskExecution
{
    public function execute(int $taskId, User $user, array $data): array
    {
        return DB::transaction(function () use ($taskId, $user, $data): array {
            $providerId = $user->serviceProvider?->id;
            abort_if($providerId === null, 403);
            $task = Task::query()->whereKey($taskId)
                ->where('service_provider_id', $providerId)->lockForUpdate()->firstOrFail();

            $responses = array_map(fn (array $item): array => [
                'key' => $item['key'], 'value' => $item['value'],
            ], $data['responses']);
            usort($responses, fn (array $a, array $b): int => strcmp($a['key'], $b['key']));
            $evidenceIds = array_map('intval', $data['evidence_ids'] ?? []);
            sort($evidenceIds);
            $correctionOfExecutionId = isset($data['correction_of_execution_id'])
                ? (int) $data['correction_of_execution_id']
                : null;
            $payload = [
                'responses' => $responses,
                'observations' => $data['observations'] ?? null,
                'evidence_ids' => $evidenceIds,
            ];
            if ($correctionOfExecutionId !== null) {
                $payload['correction_of_execution_id'] = $correctionOfExecutionId;
            }
            $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
            $existing = TaskExecution::query()->where('task_id', $taskId)
                ->where('user_id', $user->id)->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing !== null) {
                abort_unless(hash_equals($existing->payload_hash, $hash), 409,
                    'A chave de idempotência já foi usada com dados diferentes.');

                return ['record' => $existing, 'created' => false];
            }

            if ($correctionOfExecutionId !== null) {
                $rejectedExecution = TaskExecution::query()->whereKey($correctionOfExecutionId)
                    ->where('task_id', $task->id)
                    ->whereHas('rejection')
                    ->lockForUpdate()
                    ->first();

                if ($rejectedExecution === null) {
                    throw ValidationException::withMessages([
                        'correction_of_execution_id' => 'A execução a corrigir tem de estar rejeitada e pertencer a esta tarefa.',
                    ]);
                }

                if (TaskExecution::query()->where('correction_of_execution_id', $rejectedExecution->id)->exists()) {
                    abort(409, 'Esta execução rejeitada já tem uma correção registada.');
                }

                if ($task->status !== 'rejected') {
                    throw ValidationException::withMessages([
                        'correction_of_execution_id' => 'A tarefa tem de estar rejeitada para receber uma correção.',
                    ]);
                }
            }

            $evidences = TaskEvidence::query()->whereIn('id', $evidenceIds)
                ->where('task_id', $task->id)->where('uploaded_by_user_id', $user->id)
                ->lockForUpdate()->get();
            if ($evidences->count() !== count($evidenceIds)) {
                throw ValidationException::withMessages([
                    'evidence_ids' => 'Os anexos devem pertencer a esta tarefa e ao autor autenticado.',
                ]);
            }
            foreach ($evidences as $evidence) {
                if ($evidence->disk !== 'task_evidence' || ! Storage::disk('task_evidence')->exists($evidence->path)) {
                    throw ValidationException::withMessages(['evidence_ids' => 'Um dos anexos não está disponível.']);
                }
            }

            $execution = TaskExecution::query()->create([
                'task_id' => $task->id,
                'user_id' => $user->id,
                'correction_of_execution_id' => $correctionOfExecutionId,
                'idempotency_key' => $data['idempotency_key'],
                'payload_hash' => $hash,
                'responses' => $responses,
                'observations' => $payload['observations'],
                'send_state' => 'confirmed',
                'confirmed_at' => now(),
            ]);
            $execution->evidences()->attach($evidenceIds);

            if ($correctionOfExecutionId !== null) {
                $task->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                    'completed_by_user_id' => $user->id,
                ]);
            }

            return ['record' => $execution, 'created' => true];
        });
    }
}
