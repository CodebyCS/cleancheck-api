<?php

declare(strict_types=1);

namespace App\Modules\Alerts\Services;

use App\Models\Task;
use App\Modules\Alerts\Enums\AlertResolution;
use App\Modules\Alerts\Enums\AlertType;
use App\Modules\Alerts\Models\TaskAlert;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class DetectTaskAlerts
{
    /**
     * Opens alerts for late tasks and resolves the ones whose condition is gone.
     * Safe to run every minute and in parallel: the same alert is never opened twice.
     *
     * @return array{opened: int, resolved: int}
     */
    public function run(): array
    {
        $now = CarbonImmutable::now();

        $late = $this->lateStartTasks($now);
        $atRisk = $this->deadlineAtRiskTasks($now);

        $resolved = $this->resolveCleared([
            AlertType::LateStart->value => $this->ids($late),
            AlertType::DeadlineAtRisk->value => $this->ids($atRisk),
        ], $now);

        $opened = $this->open(AlertType::LateStart, $late, $now)
            + $this->open(AlertType::DeadlineAtRisk, $atRisk, $now);

        return ['opened' => $opened, 'resolved' => $resolved];
    }

    /** @return Collection<int, Task> */
    private function lateStartTasks(CarbonImmutable $now): Collection
    {
        return Task::query()
            ->where('status', 'pending')
            ->where('scheduled_start_at', '<=', $now->subMinutes((int) config('alerts.late_start_after_minutes')))
            ->where('scheduled_start_at', '>=', $this->oldest($now))
            ->get();
    }

    /** @return Collection<int, Task> */
    private function deadlineAtRiskTasks(CarbonImmutable $now): Collection
    {
        // Without a next check-in there is no window end, so there is nothing to put at risk.
        return Task::query()
            ->whereIn('status', ['pending', 'in_progress'])
            ->whereNotNull('scheduled_end_at')
            ->where('scheduled_end_at', '<=', $now->addMinutes((int) config('alerts.deadline_warning_minutes')))
            ->where('scheduled_end_at', '>=', $this->oldest($now))
            ->get();
    }

    /**
     * @param  array<string, array<int, int>>  $matching  alert type => task ids that still match
     */
    private function resolveCleared(array $matching, CarbonImmutable $now): int
    {
        $resolved = 0;

        foreach (TaskAlert::query()->active()->with('task')->get() as $alert) {
            if (isset($matching[$alert->type->value][(int) $alert->task_id])) {
                continue;
            }

            // whereNull keeps a manual resolution made at the same moment.
            $resolved += TaskAlert::query()
                ->whereKey($alert->id)
                ->whereNull('resolved_at')
                ->update([
                    'resolved_at' => $now,
                    'resolution' => $this->resolution($alert, $now)->value,
                    'active_key' => null,
                    'updated_at' => $now,
                ]);
        }

        return $resolved;
    }

    /** @param  Collection<int, Task>  $tasks */
    private function open(AlertType $type, Collection $tasks, CarbonImmutable $now): int
    {
        $opened = 0;

        foreach ($tasks as $task) {
            // The team already closed this alert by hand: opening it again every minute would be noise.
            $dismissed = TaskAlert::query()
                ->where('task_id', $task->id)
                ->where('type', $type->value)
                ->where('resolution', AlertResolution::Manual->value)
                ->exists();

            if ($dismissed) {
                continue;
            }

            // The unique active_key turns a repeated detection into a no-op.
            $opened += TaskAlert::query()->insertOrIgnore([
                'task_id' => $task->id,
                'type' => $type->value,
                'priority' => $type->priority()->value,
                'reason' => $this->reason($type),
                'action' => $type->action()->value,
                'active_key' => TaskAlert::activeKey((int) $task->id, $type),
                'detected_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $opened;
    }

    private function resolution(TaskAlert $alert, CarbonImmutable $now): AlertResolution
    {
        $task = $alert->task;
        $reference = $alert->type === AlertType::LateStart ? $task?->scheduled_start_at : $task?->scheduled_end_at;

        return match (true) {
            $task === null || $task->trashed() || $task->status === 'cancelled' => AlertResolution::TaskCancelled,
            $task->status === 'completed' => AlertResolution::TaskCompleted,
            $alert->type === AlertType::LateStart && $task->status === 'in_progress' => AlertResolution::TaskStarted,
            $reference !== null && $reference < $this->oldest($now) => AlertResolution::Expired,
            default => AlertResolution::Rescheduled,
        };
    }

    private function reason(AlertType $type): string
    {
        return match ($type) {
            AlertType::LateStart => sprintf(
                'A tarefa ainda não começou %d min depois do início previsto.',
                (int) config('alerts.late_start_after_minutes'),
            ),
            AlertType::DeadlineAtRisk => sprintf(
                'Faltam %d min ou menos para o fim do prazo e a tarefa não está concluída.',
                (int) config('alerts.deadline_warning_minutes'),
            ),
        };
    }

    private function oldest(CarbonImmutable $now): CarbonImmutable
    {
        return $now->subHours((int) config('alerts.ignore_older_than_hours'));
    }

    /**
     * @param  Collection<int, Task>  $tasks
     * @return array<int, int> task id => task id, for quick lookups
     */
    private function ids(Collection $tasks): array
    {
        $ids = $tasks->map(fn (Task $task): int => (int) $task->id)->all();

        return array_combine($ids, $ids);
    }
}