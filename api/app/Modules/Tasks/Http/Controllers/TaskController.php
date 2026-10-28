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
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;

class TaskController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    private function accessibleTasks(Request $request): Builder
    {
        $user = $request->user();
        $query = Task::query();

        if ($user->hasRole('service_provider')) {
            return $query->whereHas('serviceProvider', fn (Builder $providers) => $providers->where('user_id', $user->id));
        }

        abort_unless($user->hasRole('admin', 'supervisor'), 403);

        return $query;
    }

    public function index(Request $request): JsonResponse
    {
        $tasks = $this->accessibleTasks($request)
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

    public function show(Request $request, int $task): JsonResponse
    {
        $task = $this->accessibleTasks($request)->with('property')->findOrFail($task);

        return response()->json([
            'id' => $task->id,
            'property' => $task->property?->name,
            'address' => $task->property?->address,
            'type' => $task->type,
            'status' => $task->status,
            'scheduled_start_at' => $task->scheduled_start_at,
            'scheduled_end_at' => $task->scheduled_end_at,
            'started_at' => $task->started_at,
            'completed_at' => $task->completed_at,
        ]);
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
        return response()->json($action->manual($task, $request->validated(), $request->user()->id));
    }

    public function restore(Request $request, int $task, RestoreTask $action): JsonResponse
    {
        abort_unless($request->user()->hasRole('admin', 'supervisor'), 403);
        return response()->json($action->manual($task));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, int $task, DeleteTask $action): Response
    {
        abort_unless($request->user()->hasRole('admin', 'supervisor'), 403);
        $action->manual($task);

        return response()->noContent();
    }
}
