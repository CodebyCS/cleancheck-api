<?php

namespace App\Modules\Tasks\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\TaskCheckoutInspection;
use App\Modules\Tasks\Http\Requests\StoreCheckoutInspectionRequest;
use App\Modules\Tasks\Services\RegisterCheckoutInspection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskCheckoutInspectionController extends Controller
{
    public function store(StoreCheckoutInspectionRequest $request, int $task, RegisterCheckoutInspection $register): JsonResponse
    {
        $result = $register->execute($task, $request->user(), $request->validated());
        return response()->json(['data' => $this->payload($result['record'])], $result['created'] ? 201 : 200);
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
        $record = TaskCheckoutInspection::query()->where('task_id', $task)->with('evidences')->first();
        return response()->json(['data' => $record === null ? null : $this->payload($record)]);
    }

    private function payload(TaskCheckoutInspection $record): array
    {
        return [
            'id' => $record->id, 'task_id' => $record->task_id, 'user_id' => $record->user_id,
            'idempotency_key' => $record->idempotency_key, 'responses' => $record->responses,
            'observations' => $record->observations, 'evidence_ids' => $record->evidences->pluck('id')->all(),
            'recorded_at' => $record->recorded_at?->toISOString(),
        ];
    }
}
