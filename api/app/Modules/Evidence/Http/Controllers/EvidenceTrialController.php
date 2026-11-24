<?php

namespace App\Modules\Evidence\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\EvidenceTrial;
use App\Models\Task;
use App\Modules\Evidence\Http\Requests\StoreEvidenceTrialRequest;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class EvidenceTrialController extends Controller
{
    // Private disk: trial files are never served publicly.
    private const DISK = 'local';

    private const RETENTION_DAYS = 30;

    public function download(EvidenceTrial $evidenceTrial): StreamedResponse
    {
        abort_if(
            $evidenceTrial->received_at->lte(now()->subDays(self::RETENTION_DAYS)),
            404,
            'Ficheiro de ensaio não encontrado.',
        );

        $disk = Storage::disk(self::DISK);
        abort_unless($disk->exists($evidenceTrial->path), 404, 'Ficheiro de ensaio não encontrado.');

        return $disk->download(
            $evidenceTrial->path,
            'evidence-trial-'.$evidenceTrial->id,
            ['Content-Type' => $evidenceTrial->mime_type],
        );
    }

    public function store(StoreEvidenceTrialRequest $request): JsonResponse
    {
        // Backwards-compatible trial endpoint used before task association.
        return $this->receive($request, null);
    }

    public function storeForTask(StoreEvidenceTrialRequest $request, int $task): JsonResponse
    {
        $user = $request->user();
        $providerId = $user->serviceProvider?->id;
        $taskModel = Task::query()->findOrFail($task);

        abort_unless(
            $providerId !== null && (int) $taskModel->service_provider_id === (int) $providerId,
            403,
            'Só podes enviar ficheiros para uma tarefa atualmente atribuída a ti.',
        );

        return $this->receive($request, $taskModel);
    }

    private function receive(StoreEvidenceTrialRequest $request, ?Task $task): JsonResponse
    {
        $file = $request->file('file');
        $userId = $request->user()->id;
        $kind = StoreEvidenceTrialRequest::isPhoto($file) ? 'photo' : 'video';
        $directory = $task === null
            ? "evidence-trials/{$userId}"
            : "evidence-trials/tasks/{$task->id}/providers/{$userId}";
        $path = $file->store($directory, self::DISK);

        if (!is_string($path)) {
            abort(500, 'Não foi possível guardar o ficheiro do ensaio.');
        }

        $receivedAt = Carbon::now();
        $trial = null;

        try {
            $providerId = $request->user()->serviceProvider?->id;
            $trial = DB::transaction(function () use ($task, $providerId, $userId, $path, $kind, $file, $request, $receivedAt): EvidenceTrial {
                $lockedTask = null;

                if ($task !== null) {
                    $lockedTask = Task::query()->lockForUpdate()->findOrFail($task->id);
                    abort_unless(
                        $providerId !== null && (int) $lockedTask->service_provider_id === (int) $providerId,
                        403,
                        'Só podes enviar ficheiros para uma tarefa atualmente atribuída a ti.',
                    );
                }

                return EvidenceTrial::query()->create([
                    'task_id' => $lockedTask?->id,
                    'user_id' => $userId,
                    'path' => $path,
                    'kind' => $kind,
                    'mime_type' => $file->getMimeType(),
                    'size_bytes' => $file->getSize(),
                    'original_name' => $file->getClientOriginalName(),
                    'device' => $request->validated('device'),
                    'received_at' => $receivedAt,
                ]);
            });
        } catch (Throwable $exception) {
            Storage::disk(self::DISK)->delete($path);
            throw $exception;
        }

        $result = [
            'is_trial' => true,
            'kind' => $kind,
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'original_name' => $file->getClientOriginalName(),
            'device' => $request->validated('device'),
            'received_at' => $receivedAt->toIso8601String(),
        ];

        if ($trial !== null) {
            $result = ['id' => (int) $trial->id] + $result;
            if ($trial->task_id !== null) {
                $result = ['task_id' => (int) $trial->task_id] + $result;
            }
        }
        Log::info('Evidence trial received', $result + ['user_id' => $userId, 'task_id' => $task?->id, 'path' => $path]);

        return response()->json(['data' => $result], 201);
    }
}
