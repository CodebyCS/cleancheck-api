<?php

namespace App\Modules\Tasks\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Tasks\Http\Requests\StoreTaskRequest;
use App\Modules\Tasks\Http\Requests\UpdateTaskRequest;
use App\Models\Task;
use App\Modules\Tasks\Actions\CreateManualTask;
use App\Modules\Tasks\Actions\DeleteTask;
use App\Modules\Tasks\Actions\RestoreTask;
use App\Modules\Tasks\Actions\UpdateTask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class TaskController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        $tasks = Task::query()
            ->with('property')
            ->orderBy('scheduled_start_at')
            ->get(); 

        return response()->json(
            $tasks->map(fn (Task $task) => [
                'id' => $task->id,
                'property' => $task->property ? $task->property->name : null,
                'type' => $task->type,
                'scheduled_start_at' => $task->scheduled_start_at,
                'status' => $task->status,
            ])
        );
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreTaskRequest $request, CreateManualTask $action): JsonResponse
    {
        $task = $action->execute($request->integer('booking_id'));

        return response()->json($task, 201);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateTaskRequest $request, int $task, UpdateTask $action): JsonResponse
    {
        return response()->json($action->manual($task, $request->validated()));
    }

    public function restore(int $task, RestoreTask $action): JsonResponse
    {
        return response()->json($action->manual($task));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(int $task, DeleteTask $action): Response
    {
        $action->manual($task);

        return response()->noContent();
    }
}
