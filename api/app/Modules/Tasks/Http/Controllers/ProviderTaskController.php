<?php

namespace App\Modules\Tasks\Http\Controllers;

use App\Http\Controllers\Controller;

use App\Modules\Tasks\Http\Requests\ListProviderTasksRequest;
use App\Modules\Tasks\Http\Resources\TaskProviderResource;
use App\Modules\Tasks\Http\Requests\UpdateProviderTaskNotesRequest;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

use App\Modules\Tasks\Actions\ListTasks;
use App\Modules\Tasks\Actions\StartTask;
use App\Modules\Tasks\Actions\CompleteTask;
use App\Modules\Tasks\Actions\UpdateProviderTaskNotes;

class ProviderTaskController extends Controller
{
    public function index(ListProviderTasksRequest $request, ListTasks $action): AnonymousResourceCollection
    {
        return TaskProviderResource::collection(
            $action->forProvider($this->providerId($request), $request->validated())
        );
    }

    public function show(Request $request, int $task, ListTasks $action): TaskProviderResource
    {
        return new TaskProviderResource(
            $action->findForProvider($task, $this->providerId($request))
        );
    }

    private function providerId(Request $request): int
    {
        $id = $request->user()?->serviceProvider?->id;

        abort_if($id === null, 403, 'Utilizador sem perfil de prestador.');

        return $id;
    }

    public function start(Request $request, int $task, StartTask $action): TaskProviderResource {
        $startedTask = $action->execute(
            $task,
            $this->providerId($request),
        );

        return new TaskProviderResource($startedTask);
    }

    public function complete(Request $request, int $task, CompleteTask $action): TaskProviderResource {
        $completedTask = $action->forProvider(
            $task,
            $this->providerId($request),
            $request->user()->id,
        );

        return new TaskProviderResource($completedTask);

    }
    
    public function updateNotes(
    UpdateProviderTaskNotesRequest $request,
    int $task,
    UpdateProviderTaskNotes $action,
    ): TaskProviderResource {
        $updatedTask = $action->execute(
            $task,
            $this->providerId($request),
            $request->validated('provider_notes'),
        );

        return new TaskProviderResource($updatedTask);
    }

}