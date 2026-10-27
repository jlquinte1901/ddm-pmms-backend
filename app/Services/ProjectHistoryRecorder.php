<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectHistory;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Writes the project's business-level, append-only history. One row is written
 * per changed field, all sharing a change_set_id. Creation writes a single
 * snapshot row. Callers invoke this inside the same transaction as the change.
 */
final class ProjectHistoryRecorder
{
    public function __construct(private readonly ProjectSnapshot $snapshot) {}

    public function recordCreation(Project $project, User $actor): void
    {
        ProjectHistory::query()->create([
            'project_id' => $project->getKey(),
            'user_id' => $actor->getKey(),
            'change_set_id' => (string) Str::uuid(),
            'action' => 'created',
            'field_name' => null,
            'previous_value' => null,
            'new_value' => $this->snapshot->forProject($project),
            'reason' => null,
        ]);
    }

    /**
     * Record an event that does not change a project field, such as a progress
     * report that keeps the same percentage.
     *
     * @param  array<string, mixed>  $newValue
     */
    public function recordEvent(
        Project $project,
        User $actor,
        string $action,
        array $newValue,
        ?string $reason = null,
    ): void {
        ProjectHistory::query()->create([
            'project_id' => $project->getKey(),
            'user_id' => $actor->getKey(),
            'change_set_id' => (string) Str::uuid(),
            'action' => $action,
            'field_name' => null,
            'previous_value' => null,
            'new_value' => $newValue,
            'reason' => $reason,
        ]);
    }

    /**
     * @param  array<string, array{0: mixed, 1: mixed}>  $changes
     */
    public function record(
        Project $project,
        User $actor,
        string $action,
        array $changes,
        ?string $reason = null,
    ): void {
        if ($changes === []) {
            return;
        }

        $changeSetId = (string) Str::uuid();

        foreach ($changes as $field => [$previous, $new]) {
            ProjectHistory::query()->create([
                'project_id' => $project->getKey(),
                'user_id' => $actor->getKey(),
                'change_set_id' => $changeSetId,
                'action' => $action,
                'field_name' => $field,
                'previous_value' => $this->snapshot->value($previous),
                'new_value' => $this->snapshot->value($new),
                'reason' => $reason,
            ]);
        }
    }
}
