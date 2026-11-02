<?php

namespace App\Modules\Tasks\Http\Controllers;

use App\Http\Controllers\Controller;

use App\Modules\Tasks\Http\Requests\StoreTaskRequest;
use App\Modules\Tasks\Http\Requests\UpdateTaskRequest;
use App\Modules\Tasks\Http\Requests\ListTasksRequest;

use App\Modules\Tasks\Http\Resources\TaskStaffResource;

use App\Modules\Tasks\Actions\CreateManualTask;
use App\Modules\Tasks\Actions\DeleteTask;
use App\Modules\Tasks\Actions\RestoreTask;
use App\Modules\Tasks\Actions\UpdateTask;
use App\Modules\Tasks\Actions\ListTasks;
use App\Modules\Tasks\Actions\CancelTask;
use App\Modules\Tasks\Actions\CompleteTask;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TaskController extends Controller
{
    public function index(ListTasksRequest $request, ListTasks $action): AnonymousResourceCollection
    {
        return TaskStaffResource::collection($action->forStaff($request->validated()));
    }

    public function show(int $task, ListTasks $action): TaskStaffResource
    {
        return new TaskStaffResource($action->findForStaff($task));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreTaskRequest $request, CreateManualTask $action): JsonResponse
    {
        $task = $action->execute($request->integer('booking_id'));

        return (new TaskStaffResource($task))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateTaskRequest $request, int $task, UpdateTask $action): JsonResponse
    {
        $updatedTask = $action->manual(
            $task,
            $request->validated(),
            $request->user()->id,
        );

        return (new TaskStaffResource($updatedTask))->response();
    }

    /**
     * Complete an in-progress task on behalf of staff.
     */
    public function complete(
        Request $request,
        int $task,
        CompleteTask $action,
    ): TaskStaffResource {
        $completedTask = $action->forStaff(
            $task,
            $request->user()->id,
        );

        return new TaskStaffResource($completedTask);
    }


    public function restore(Request $request, int $task, RestoreTask $action): JsonResponse
    {
        $restoredTask = $action->manual($task);

        return (new TaskStaffResource($restoredTask))->response();
    }

    /** Cancel a task: it becomes `cancelled` and is soft deleted. */
    public function cancel(int $task, CancelTask $action): Response
    {
        $action->manual($task);

        return response()->noContent();
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
