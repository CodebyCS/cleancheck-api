<?php

declare(strict_types=1);

namespace App\Modules\Alerts\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Alerts\Enums\AlertPriority;
use App\Modules\Alerts\Enums\AlertResolution;
use App\Modules\Alerts\Http\Requests\ResolveTaskAlertRequest;
use App\Modules\Alerts\Http\Resources\TaskAlertResource;
use App\Modules\Alerts\Models\TaskAlert;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

final class TaskAlertController extends Controller
{
    private const PER_PAGE = 20;

    private const RELATIONS = ['task.property', 'task.serviceProvider.user', 'resolvedBy'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(['active', 'resolved'])],
            'priority' => ['sometimes', Rule::enum(AlertPriority::class)],
        ], [
            'status.in' => 'O estado tem de ser active ou resolved.',
            'priority.enum' => 'A prioridade tem de ser medium ou high.',
        ]);

        $resolved = ($filters['status'] ?? 'active') === 'resolved';

        $alerts = TaskAlert::query()
            ->when(
                $resolved,
                // History: most recent resolution first.
                fn (Builder $query) => $query->whereNotNull('resolved_at')
                    ->orderByDesc('resolved_at')
                    ->orderByDesc('id'),
                // Active: high priority first, then the oldest, which has waited the longest.
                fn (Builder $query) => $query->whereNull('resolved_at')
                    ->orderByRaw("case when priority = 'high' then 0 else 1 end")
                    ->orderBy('detected_at')
                    ->orderBy('id'),
            )
            ->when(isset($filters['priority']), fn (Builder $query) => $query->where('priority', $filters['priority']))
            ->with(self::RELATIONS)
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        // The panel polls this endpoint: the counters feed the badge without a second request.
        return TaskAlertResource::collection($alerts)->additional(['meta' => [
            'activeCount' => TaskAlert::query()->active()->count(),
            'highCount' => TaskAlert::query()->active()->where('priority', AlertPriority::High->value)->count(),
        ]]);
    }

    public function resolve(ResolveTaskAlertRequest $request, TaskAlert $alert): TaskAlertResource|JsonResponse
    {
        // whereNull makes the check and the update one step: two supervisors cannot both resolve it.
        $updated = TaskAlert::query()
            ->whereKey($alert->id)
            ->whereNull('resolved_at')
            ->update([
                'resolved_at' => now(),
                'resolution' => AlertResolution::Manual->value,
                'resolution_note' => $request->validated('reason'),
                'resolved_by_user_id' => $request->user()->id,
                'active_key' => null,
                'updated_at' => now(),
            ]);

        if ($updated === 0) {
            return response()->json(['message' => 'Este alerta já foi resolvido.'], 409);
        }

        return new TaskAlertResource($alert->fresh(self::RELATIONS));
    }
}