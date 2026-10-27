<?php

namespace App\Services;

use App\Enums\ProjectStatus;
use App\Enums\RoleName;
use App\Exceptions\StateConflictException;
use App\Models\Project;
use App\Models\ProjectUpdate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Owns every PMMS project lifecycle transition. Each method runs one
 * transaction, locks the project row, and writes both project history and the
 * system audit row before the transaction commits.
 */
final class ProjectService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly ProjectHistoryRecorder $history,
        private readonly ProjectSnapshot $snapshot,
        private readonly ProjectIndicatorCalculator $indicator,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): Project
    {
        return DB::transaction(function () use ($data, $actor): Project {
            $project = new Project;
            $project->fill($data);
            $project->forceFill([
                'assigned_pe_id' => $actor->getKey(),
                'created_by' => $actor->getKey(),
                'status' => ProjectStatus::NotStarted,
                'progress_percentage' => 0,
            ])->save();

            $snapshot = $this->snapshot->forProject($project);

            $this->history->recordCreation($project, $actor);
            $this->audit->record(
                module: 'pmms',
                action: 'project_created',
                auditable: $project,
                newValues: $snapshot,
                actor: $actor,
            );

            return $this->loadProject($project);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Project $project, array $data, User $actor): Project
    {
        return DB::transaction(function () use ($project, $data, $actor): Project {
            $project = $this->lock($project);
            $old = $this->snapshot->forProject($project);

            if ($project->status !== ProjectStatus::NotStarted) {
                foreach (['planned_start_date', 'planned_finish_date'] as $field) {
                    if (array_key_exists($field, $data) && (string) $data[$field] !== (string) ($old[$field] ?? '')) {
                        throw new StateConflictException('Planned dates can only be changed while the project is not started.');
                    }
                }
            }

            $project->fill($data)->save();

            $new = $this->snapshot->forProject($project);
            $changes = $this->snapshot->changes($old, $new);

            if ($changes === []) {
                return $this->loadProject($project);
            }

            $this->history->record($project, $actor, 'updated', $changes);
            $this->audit->record(
                module: 'pmms',
                action: 'project_updated',
                auditable: $project,
                oldValues: $this->changedValues($changes, 0),
                newValues: $this->changedValues($changes, 1),
                actor: $actor,
            );

            return $this->loadProject($project);
        });
    }

    public function start(Project $project, ?string $remarks, User $actor): Project
    {
        return DB::transaction(function () use ($project, $remarks, $actor): Project {
            $project = $this->lock($project);

            if ($project->status !== ProjectStatus::NotStarted) {
                throw new StateConflictException('Only a not started project can be started.');
            }

            $old = $this->snapshot->forProject($project);
            $today = $this->indicator->today()->toDateString();

            $project->forceFill([
                'status' => ProjectStatus::Ongoing,
                'actual_start_date' => $project->actual_start_date ?? $today,
            ])->save();

            $changes = $this->snapshot->changes($old, $this->snapshot->forProject($project));

            $this->history->record($project, $actor, 'started', $changes, $remarks);
            $this->audit->record(
                module: 'pmms',
                action: 'project_started',
                auditable: $project,
                oldValues: $this->changedValues($changes, 0),
                newValues: $this->changedValues($changes, 1),
                context: $remarks === null ? [] : ['reason' => $remarks],
                actor: $actor,
            );

            return $this->loadProject($project);
        });
    }

    /**
     * @param  array{report_date: string, progress_percentage: int|string, accomplishment?: string|null, remarks?: string|null}  $data
     */
    public function recordProgress(Project $project, array $data, User $actor): Project
    {
        return DB::transaction(function () use ($project, $data, $actor): Project {
            $project = $this->lock($project);

            if (! in_array($project->status, [ProjectStatus::NotStarted, ProjectStatus::Ongoing], true)) {
                throw new StateConflictException('Only a not started or ongoing project can receive a progress report.');
            }

            $progress = (int) $data['progress_percentage'];
            $remarks = $data['remarks'] ?? null;

            if ($progress < $project->progress_percentage && blank($remarks)) {
                throw ValidationException::withMessages([
                    'remarks' => 'Remarks are required when progress is lowered.',
                ]);
            }

            $old = $this->snapshot->forProject($project);
            $today = $this->indicator->today()->toDateString();

            $update = new ProjectUpdate;
            $update->fill([
                'project_id' => $project->getKey(),
                'report_date' => $data['report_date'],
                'previous_progress' => $project->progress_percentage,
                'progress_percentage' => $progress,
                'accomplishment' => $data['accomplishment'] ?? null,
                'remarks' => $remarks,
            ]);
            $update->forceFill(['reported_by' => $actor->getKey()])->save();

            $force = ['progress_percentage' => $progress];

            if ($progress === 100) {
                $force['status'] = ProjectStatus::Completed;
                $force['completed_at'] = now();
                $force['paused_since'] = null;
            } elseif ($project->status === ProjectStatus::NotStarted) {
                $force['status'] = ProjectStatus::Ongoing;
                $force['paused_since'] = null;
            }

            if ($project->actual_start_date === null
                && ($project->status === ProjectStatus::NotStarted || $progress > 0)) {
                $force['actual_start_date'] = $today;
            }

            $project->forceFill($force)->save();

            $new = $this->snapshot->forProject($project);
            $changes = $this->snapshot->changes($old, $new);
            $action = $progress === 100 ? 'completed' : 'progress_updated';

            if ($changes === []) {
                $this->history->recordEvent($project, $actor, 'progress_reported', [
                    'report_date' => $data['report_date'],
                    'progress_percentage' => $progress,
                    'accomplishment' => $data['accomplishment'] ?? null,
                    'remarks' => $remarks,
                ], $remarks);
            } else {
                $this->history->record($project, $actor, $action, $changes, $remarks);
            }

            $this->audit->record(
                module: 'pmms',
                action: $progress === 100 ? 'project_completed' : 'project_progress_updated',
                auditable: $project,
                oldValues: $changes === []
                    ? ['progress_percentage' => $old['progress_percentage'], 'status' => $old['status']]
                    : $this->changedValues($changes, 0),
                newValues: $changes === []
                    ? ['progress_percentage' => $progress, 'status' => $new['status']]
                    : $this->changedValues($changes, 1),
                context: [
                    'project_update_id' => $update->getKey(),
                    'report_date' => $data['report_date'],
                ],
                actor: $actor,
            );

            return $this->loadProject($project);
        });
    }

    public function pause(Project $project, ProjectStatus $status, string $reason, User $actor): Project
    {
        if (! in_array($status, [ProjectStatus::Suspended, ProjectStatus::Postponed], true)) {
            throw ValidationException::withMessages([
                'status' => 'The project can only be suspended or postponed.',
            ]);
        }

        return DB::transaction(function () use ($project, $status, $reason, $actor): Project {
            $project = $this->lock($project);

            if (! $project->status->isOpen()) {
                throw new StateConflictException('Only a not started or ongoing project can be paused.');
            }

            $old = $this->snapshot->forProject($project);

            $project->forceFill([
                'status' => $status,
                'paused_since' => $this->indicator->today()->toDateString(),
            ])->save();

            $changes = $this->snapshot->changes($old, $this->snapshot->forProject($project));
            $action = $status === ProjectStatus::Suspended ? 'suspended' : 'postponed';

            $this->history->record($project, $actor, $action, $changes, $reason);
            $this->audit->record(
                module: 'pmms',
                action: 'project_'.$action,
                auditable: $project,
                oldValues: $this->changedValues($changes, 0),
                newValues: $this->changedValues($changes, 1),
                context: ['reason' => $reason],
                actor: $actor,
            );

            return $this->loadProject($project);
        });
    }

    public function resume(
        Project $project,
        string $revisedFinishDate,
        string $remarks,
        User $actor,
    ): Project {
        return DB::transaction(function () use ($project, $revisedFinishDate, $remarks, $actor): Project {
            $project = $this->lock($project);

            if (! $project->status->isPaused()) {
                throw new StateConflictException('Only a suspended or postponed project can be resumed.');
            }

            $newFinish = CarbonImmutable::parse(
                $revisedFinishDate,
                (string) config('app.timezone', 'Asia/Manila'),
            )->startOfDay();

            if ($newFinish->lt($this->indicator->today())) {
                throw ValidationException::withMessages([
                    'revised_finish_date' => 'The revised finish date cannot be before today.',
                ]);
            }

            $old = $this->snapshot->forProject($project);

            $project->forceFill([
                'status' => ProjectStatus::Ongoing,
                'paused_since' => null,
                'revised_finish_date' => $newFinish->toDateString(),
            ])->save();

            $changes = $this->snapshot->changes($old, $this->snapshot->forProject($project));

            $this->history->record($project, $actor, 'resumed', $changes, $remarks);
            $this->audit->record(
                module: 'pmms',
                action: 'project_resumed',
                auditable: $project,
                oldValues: $this->changedValues($changes, 0),
                newValues: $this->changedValues($changes, 1),
                context: ['reason' => $remarks],
                actor: $actor,
            );

            return $this->loadProject($project);
        });
    }

    public function terminate(Project $project, string $reason, User $actor): Project
    {
        return DB::transaction(function () use ($project, $reason, $actor): Project {
            $project = $this->lock($project);

            if ($project->status === ProjectStatus::Terminated) {
                throw new StateConflictException('The project is already terminated.');
            }

            $old = $this->snapshot->forProject($project);

            $project->forceFill([
                'status' => ProjectStatus::Terminated,
                'paused_since' => null,
            ])->save();

            $changes = $this->snapshot->changes($old, $this->snapshot->forProject($project));

            $this->history->record($project, $actor, 'terminated', $changes, $reason);
            $this->audit->record(
                module: 'pmms',
                action: 'project_terminated',
                auditable: $project,
                oldValues: $this->changedValues($changes, 0),
                newValues: $this->changedValues($changes, 1),
                context: ['reason' => $reason],
                actor: $actor,
            );

            return $this->loadProject($project);
        });
    }

    public function reopen(Project $project, int $progress, string $reason, User $actor): Project
    {
        return DB::transaction(function () use ($project, $progress, $reason, $actor): Project {
            $project = $this->lock($project);

            if (! in_array($project->status, [ProjectStatus::Completed, ProjectStatus::Terminated], true)) {
                throw new StateConflictException('Only a completed or terminated project can be reopened.');
            }

            if ($progress >= 100) {
                throw ValidationException::withMessages([
                    'progress_percentage' => 'Reopened progress must be below 100.',
                ]);
            }

            $old = $this->snapshot->forProject($project);

            $project->forceFill([
                'status' => ProjectStatus::Ongoing,
                'progress_percentage' => $progress,
                'completed_at' => null,
                'paused_since' => null,
                'actual_finish_date' => null,
            ])->save();

            $changes = $this->snapshot->changes($old, $this->snapshot->forProject($project));

            $this->history->record($project, $actor, 'reopened', $changes, $reason);
            $this->audit->record(
                module: 'pmms',
                action: 'project_reopened',
                auditable: $project,
                oldValues: $this->changedValues($changes, 0),
                newValues: $this->changedValues($changes, 1),
                context: ['reason' => $reason],
                actor: $actor,
            );

            return $this->loadProject($project);
        });
    }

    public function reassign(Project $project, int $newProjectEngineerId, string $reason, User $actor): Project
    {
        return DB::transaction(function () use ($project, $newProjectEngineerId, $reason, $actor): Project {
            $project = $this->lock($project);

            $newOwner = User::query()
                ->whereKey($newProjectEngineerId)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->whereHas('role', fn ($query) => $query->where('name', RoleName::ProjectEngineer->value))
                ->first();

            if ($newOwner === null) {
                throw ValidationException::withMessages([
                    'assigned_pe_id' => 'The selected user must be an active Project Engineer.',
                ]);
            }

            if ($project->assigned_pe_id === $newOwner->getKey()) {
                throw new StateConflictException('The project is already assigned to that Project Engineer.');
            }

            $old = $this->snapshot->forProject($project);

            $project->forceFill(['assigned_pe_id' => $newOwner->getKey()])->save();

            $changes = $this->snapshot->changes($old, $this->snapshot->forProject($project));

            $this->history->record($project, $actor, 'reassigned', $changes, $reason);
            $this->audit->record(
                module: 'pmms',
                action: 'project_reassigned',
                auditable: $project,
                oldValues: $this->changedValues($changes, 0),
                newValues: $this->changedValues($changes, 1),
                context: ['reason' => $reason],
                actor: $actor,
            );

            return $this->loadProject($project);
        });
    }

    public function delete(Project $project, string $reason, User $actor): void
    {
        DB::transaction(function () use ($project, $reason, $actor): void {
            $project = $this->lock($project);

            if ($project->trashed()) {
                throw new StateConflictException('The project is already deleted.');
            }

            $project->delete();

            $changes = [
                'deleted_at' => [null, $project->deleted_at?->toIso8601String()],
            ];

            $this->history->record($project, $actor, 'deleted', $changes, $reason);
            $this->audit->record(
                module: 'pmms',
                action: 'project_deleted',
                auditable: $project,
                oldValues: $this->changedValues($changes, 0),
                newValues: $this->changedValues($changes, 1),
                context: ['reason' => $reason],
                actor: $actor,
            );
        });
    }

    public function restore(Project $project, string $reason, User $actor): Project
    {
        return DB::transaction(function () use ($project, $reason, $actor): Project {
            $project = $this->lock($project, withTrashed: true);

            if (! $project->trashed()) {
                throw new StateConflictException('The project is not deleted.');
            }

            $oldDeletedAt = $project->deleted_at?->toIso8601String();
            $project->restore();

            $changes = [
                'deleted_at' => [$oldDeletedAt, null],
            ];

            $this->history->record($project, $actor, 'restored', $changes, $reason);
            $this->audit->record(
                module: 'pmms',
                action: 'project_restored',
                auditable: $project,
                oldValues: $this->changedValues($changes, 0),
                newValues: $this->changedValues($changes, 1),
                context: ['reason' => $reason],
                actor: $actor,
            );

            return $this->loadProject($project);
        });
    }

    private function lock(Project $project, bool $withTrashed = false): Project
    {
        $query = Project::query();

        if ($withTrashed) {
            $query->withTrashed();
        }

        return $query->whereKey($project->getKey())->lockForUpdate()->firstOrFail();
    }

    private function loadProject(Project $project): Project
    {
        return $project->refresh()->load(['category', 'assignedPe', 'creator']);
    }

    /**
     * @param  array<string, array{0: mixed, 1: mixed}>  $changes
     * @return array<string, mixed>
     */
    private function changedValues(array $changes, int $index): array
    {
        $values = [];

        foreach ($changes as $field => $pair) {
            $values[$field] = $pair[$index];
        }

        return $values;
    }
}
