<?php

namespace App\Modules\Checklists\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Task;
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
