<?php

namespace App\Services;

use App\Enums\ProjectIndicator;
use App\Enums\ProjectStatus;
use App\Models\Project;
use Carbon\CarbonImmutable;

/**
 * Computes the 35-day monitoring indicator at read time. The indicator is
 * never stored and never changes the project status.
 */
final class ProjectIndicatorCalculator
{
    public function today(): CarbonImmutable
    {
        return CarbonImmutable::now((string) config('app.timezone', 'Asia/Manila'))->startOfDay();
    }

    public function indicator(Project $project, ?CarbonImmutable $today = null): ProjectIndicator
    {
        if ($project->status === ProjectStatus::Completed) {
            return ProjectIndicator::Completed;
        }

        if ($project->status === ProjectStatus::Terminated) {
            return ProjectIndicator::Terminated;
        }

        if ($project->status->isPaused()) {
            return ProjectIndicator::Paused;
        }

        $daysRemaining = $this->daysRemaining($project, $today) ?? 0;

        return match (true) {
            $daysRemaining < 0 => ProjectIndicator::Overdue,
            $daysRemaining <= 35 => ProjectIndicator::Critical,
            default => ProjectIndicator::Normal,
        };
    }

    public function daysRemaining(Project $project, ?CarbonImmutable $today = null): ?int
    {
        if ($project->effective_finish_date === null) {
            return null;
        }

        $today ??= $this->today();
        $finish = CarbonImmutable::parse(
            $project->effective_finish_date->toDateString(),
            (string) config('app.timezone', 'Asia/Manila'),
        )->startOfDay();

        return (int) $today->startOfDay()->diffInDays($finish, false);
    }

    public function daysPaused(Project $project, ?CarbonImmutable $today = null): ?int
    {
        if (! $project->status->isPaused() || $project->paused_since === null) {
            return null;
        }

        $today ??= $this->today();
        $pausedSince = CarbonImmutable::parse(
            $project->paused_since->toDateString(),
            (string) config('app.timezone', 'Asia/Manila'),
        )->startOfDay();

        return max(0, (int) $pausedSince->diffInDays($today->startOfDay(), false));
    }
}
