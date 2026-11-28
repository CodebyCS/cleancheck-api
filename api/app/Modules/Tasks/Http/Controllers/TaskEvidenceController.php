<?php

namespace App\Modules\Tasks\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\TaskEvidence;
use App\Modules\Evidence\Http\Requests\StoreEvidenceTrialRequest;
use App\Modules\Tasks\Http\Requests\StoreTaskEvidenceRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TaskEvidenceController extends Controller
{
    public function store(
        StoreTaskEvidenceRequest $request,
        int $task
    ): JsonResponse {
        $providerId = $request->user()->serviceProvider?->id;

        abort_if($providerId === null, 403, 'Utilizador sem perfil de prestador.');

        $taskModel = Task::query()
            ->whereKey($task)
            ->where('service_provider_id', $providerId)
            ->firstOrFail();

        if (!in_array($taskModel->status, ['in_progress', 'completed', 'rejected'], true)) {
            throw ValidationException::withMessages([
                'status' => 'Só é possível anexar mídia a tarefas em andamento, rejeitadas ou concluídas.',
            ]);
        }

        $file = $request->file('file');
        $isPhoto = StoreEvidenceTrialRequest::isPhoto($file);
        $kind = $isPhoto ? 'photo' : 'video';
        $diskName = 'task_evidence';

        $path = $file->store("tasks/{$taskModel->id}", $diskName);

        if ($path === false) {
            abort(500, 'Não foi possível guardar o anexo.');
        }

        try {
            $evidence = DB::transaction(function () use ($taskModel, $providerId, $request, $kind, $diskName, $path, $file): TaskEvidence {
                // Recheck ownership after the upload: reassignment may have occurred meanwhile.
                $current = Task::query()->whereKey($taskModel->id)
                    ->where('service_provider_id', $providerId)->lockForUpdate()->firstOrFail();
                if (! in_array($current->status, ['in_progress', 'completed', 'rejected'], true)) {
                    throw ValidationException::withMessages([
                        'status' => 'Só é possível anexar mídia a tarefas em andamento, rejeitadas ou concluídas.',
                    ]);
                }

                return TaskEvidence::create([
                    'task_id' => $current->id,
                    'uploaded_by_user_id' => $request->user()->id,
                    'kind' => $kind,
                    'disk' => $diskName,
                    'path' => $path,
                    'mime_type' => $file->getMimeType(),
                    'size_bytes' => $file->getSize(),
                    'original_name' => $file->getClientOriginalName(),
                ]);
            });
        } catch (\Throwable $exception) {
            Storage::disk($diskName)->delete($path);
            throw $exception;
        }

        return response()->json([
            'data' => [
                'id' => $evidence->id,
                'kind' => $evidence->kind,
                'mime_type' => $evidence->mime_type,
                'size_bytes' => $evidence->size_bytes,
            ],
        ], 201);
    }

    public function index(int $task): JsonResponse
    {
        $taskModel = Task::withTrashed()
            ->whereKey($task)
            ->firstOrFail();

        $data = $taskModel->evidences()
            ->orderBy('id')
            ->get()
            ->map(fn (TaskEvidence $evidence): array => [
                'id' => $evidence->id,
                'kind' => $evidence->kind,
                'mime_type' => $evidence->mime_type,
                'size_bytes' => $evidence->size_bytes,
                'original_name' => $evidence->original_name,
                'created_at' => $evidence->created_at?->toISOString(),
                'file_url' => "/api/tasks/{$taskModel->id}/evidence/{$evidence->id}/file",
            ]);

        return response()->json(['data' => $data]);
    }

    public function file(int $task, int $evidence): StreamedResponse
    {
        $taskModel = Task::withTrashed()
            ->whereKey($task)
            ->firstOrFail();

        $record = TaskEvidence::query()
            ->where('task_id', $taskModel->id)
            ->whereKey($evidence)
            ->firstOrFail();

        abort_unless($record->disk === 'task_evidence', 404);

        $disk = Storage::disk($record->disk);

        abort_unless($disk->exists($record->path), 404);

        $stream = $disk->readStream($record->path);

        abort_unless(is_resource($stream), 404);

        return response()->stream(
            function () use ($stream): void {
                try {
                    fpassthru($stream);
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }
            },
            200,
            [
                'Content-Type' => $record->mime_type,
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }
}
