<?php

namespace App\Modules\Tasks\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\TaskImpediment;
use App\Modules\Tasks\Http\Requests\StoreTaskImpedimentRequest;
use App\Modules\Tasks\Services\RegisterTaskImpediment;
use Illuminate\Http\JsonResponse;

class TaskImpedimentController extends Controller
{
    public function store(StoreTaskImpedimentRequest $request, Task $task, RegisterTaskImpediment $register): JsonResponse
    {
        $result = $register->execute($task, $request->user(), $request->validated());
        if ($result['conflict']) {
            return response()->json(['message' => 'A chave de idempotência já foi usada com dados diferentes.'], 409);
        }

        return response()->json($this->payload($result['record']), $result['created'] ? 201 : 200);
    }

    private function payload(TaskImpediment $record): array
    {
        return [
            'id' => $record->id,
            'task_id' => $record->task_id,
            'user_id' => $record->user_id,
            'reason' => $record->reason,
            'description' => $record->description,
            'created_at' => $record->created_at,
        ];
    }
}
