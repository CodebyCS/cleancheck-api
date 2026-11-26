<?php

namespace App\Modules\Checklists\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Modules\Checklists\Actions\MarkChecklistItem;
use App\Modules\Checklists\Http\Requests\MarkChecklistItemRequest;
use App\Modules\Checklists\Http\Resources\TaskChecklistResource;
use App\Modules\Checklists\Services\TaskChecklists;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskChecklistController extends Controller
{
    public function __construct(private readonly TaskChecklists $checklists) {}

    // Staff: any task, including deleted ones (same as the task detail).
    public function showForStaff(int $task): JsonResponse
    {
        return $this->respond(Task::withTrashed()->findOrFail($task));
    }

    // Provider: only their own tasks; anything else is a 404.
    public function showForProvider(Request $request, int $task): JsonResponse
    {
        return $this->respond($this->providerTask($request, $task));
    }

    // Provider: marks a single item of their own task as done or not done.
    public function markItem(MarkChecklistItemRequest $request, int $task, int $item, MarkChecklistItem $action): JsonResponse
    {
        $checklist = $action->execute(
            $this->providerTask($request, $task),
            $item,
            $request->boolean('done'),
            $request->user(),
        );

        return (new TaskChecklistResource($checklist))->response();
    }

    private function respond(Task $task): JsonResponse
    {
        $checklist = $this->checklists->forTask($task);

        // No checklist configured for this task type yet.
        if ($checklist === null) {
            return response()->json(['data' => null]);
        }

        // Always 200, even when the version was linked during this read.
        return (new TaskChecklistResource($checklist))->response()->setStatusCode(200);
    }

    private function providerTask(Request $request, int $task): Task
    {
        $providerId = $request->user()?->serviceProvider?->id;

        abort_if($providerId === null, 403, 'Utilizador sem perfil de prestador.');

        return Task::query()
            ->where('service_provider_id', $providerId)
            ->findOrFail($task);
    }
}
