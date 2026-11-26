<?php

namespace App\Modules\Checklists\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Checklists\Http\Requests\StoreChecklistTemplateRequest;
use App\Modules\Checklists\Http\Resources\ChecklistTemplateResource;
use App\Modules\Checklists\Models\ChecklistTemplate;
use App\Modules\Checklists\Services\PublishChecklistVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;

class ChecklistTemplateController extends Controller
{
    // All versions, newest first. ?task_type= filters; ?current=1 returns only the version in force per type.
    public function index(Request $request): AnonymousResourceCollection
    {
        $templates = ChecklistTemplate::query()
            ->withCount('items')
            ->when($request->filled('task_type'), fn ($query) => $query->where('task_type', $request->string('task_type')))
            ->orderBy('task_type')
            ->orderByDesc('version')
            ->get();

        $this->markCurrent($templates);

        if ($request->boolean('current')) {
            $templates = $templates->filter(fn (ChecklistTemplate $template) => $template->is_current)->values();
        }

        return ChecklistTemplateResource::collection($templates);
    }

    public function show(ChecklistTemplate $checklistTemplate): ChecklistTemplateResource
    {
        $checklistTemplate->load('items');
        $this->markCurrent(collect([$checklistTemplate]));

        return new ChecklistTemplateResource($checklistTemplate);
    }

    // There is no update or delete: saving a checklist publishes a new version for new tasks.
    public function store(StoreChecklistTemplateRequest $request, PublishChecklistVersion $publish): JsonResponse
    {
        $template = $publish->handle(
            $request->validated('task_type'),
            $request->validated('name'),
            $request->validated('items'),
            $request->user(),
        );

        $template->setAttribute('is_current', true);

        return (new ChecklistTemplateResource($template))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * @param  Collection<int, ChecklistTemplate>  $templates
     */
    private function markCurrent(Collection $templates): void
    {
        $latest = ChecklistTemplate::query()
            ->whereIn('task_type', $templates->pluck('task_type')->unique())
            ->selectRaw('task_type, MAX(version) as version')
            ->groupBy('task_type')
            ->pluck('version', 'task_type');

        $templates->each(fn (ChecklistTemplate $template) => $template->setAttribute(
            'is_current',
            (int) ($latest[$template->task_type] ?? 0) === $template->version,
        ));
    }
}
