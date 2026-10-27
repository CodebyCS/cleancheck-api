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

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TaskController extends Controller
{
    /**
     * Display a listing of the resource.
     */

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
