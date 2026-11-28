<?php

namespace App\Modules\Tasks\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\TaskExecution;
use App\Modules\Tasks\Http\Requests\RejectTaskExecutionRequest;
use App\Modules\Tasks\Http\Requests\StoreTaskExecutionRequest;
use App\Modules\Tasks\Services\RegisterTaskExecution;
use App\Modules\Tasks\Services\RejectTaskExecution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskExecutionController extends Controller
{
    public function store(StoreTaskExecutionRequest $request, int $task, RegisterTaskExecution $register): JsonResponse
    {
        $result = $register->execute($task, $request->user(), $request->validated());

        return response()->json(['data' => $this->payload($result['record'])], $result['created'] ? 201 : 200);
    }

    public function reject(
        RejectTaskExecutionRequest $request,
        int $task,
        int $execution,
        RejectTaskExecution $reject,
    ): JsonResponse {
        $record = $reject->execute($task, $execution, $request->user(), $request->validated('reason'));

        return response()->json(['data' => $this->payload($record)], 201);
    }

    public function index(Request $request, int $task): JsonResponse
    {
        $query = Task::query()->whereKey($task);
        if ($request->user()->hasRole('service_provider')) {
            $providerId = $request->user()->serviceProvider?->id;
            abort_if($providerId === null, 403);
            $query->where('service_provider_id', $providerId);
        } else {
            $query->withTrashed();
        }
        $query->firstOrFail();
        $records = TaskExecution::query()->where('task_id', $task)->with(['evidences', 'rejection'])
            ->orderByDesc('id')->paginate(20);
        $records->through(fn (TaskExecution $record): array => $this->payload($record));

        return response()->json($records);
    }

    private function payload(TaskExecution $record): array
    {
        return [
            'id' => $record->id,
            'task_id' => $record->task_id,
            'user_id' => $record->user_id,
            'idempotency_key' => $record->idempotency_key,
            'correction_of_execution_id' => $record->correction_of_execution_id,
            'responses' => $record->responses,
            'observations' => $record->observations,
            'evidence_ids' => $record->evidences->pluck('id')->all(),
            'send_state' => $record->send_state,
            'confirmed_at' => $record->confirmed_at?->toISOString(),
            'rejection' => $record->rejection === null ? null : [
                'reason' => $record->rejection->reason,
                'rejected_by_user_id' => $record->rejection->rejected_by_user_id,
                'rejected_at' => $record->rejection->created_at?->toISOString(),
            ],
        ];
    }
}
