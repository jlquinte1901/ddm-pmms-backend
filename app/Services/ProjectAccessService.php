<?php

namespace App\Services;

use App\Enums\PermissionName;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Centralizes PMMS visibility and ownership rules. A PE sees and mutates only
 * projects assigned to him; a user with view-all sees every project. A user
 * who can see a project but lacks the write permission gets 403, while a PE
 * probing another PE's project gets 404.
 */
final class ProjectAccessService
{
    public function __construct(private readonly AuthorizationService $authorization) {}

    public function canViewAll(User $user): bool
    {
        return $this->authorization->hasPermission($user, PermissionName::PmmsProjectsViewAll);
    }

    public function canViewAny(User $user): bool
    {
        return $this->canViewAll($user)
            || $this->authorization->hasPermission($user, PermissionName::PmmsProjectsViewOwn);
    }

    public function visibleQuery(User $user): Builder
    {
        $query = Project::query();

        if ($this->canViewAll($user)) {
            return $query;
        }

        if ($this->authorization->hasPermission($user, PermissionName::PmmsProjectsViewOwn)) {
            return $query->ownedBy($user);
        }

        throw new AuthorizationException('This action is unauthorized.');
    }

    public function ensureVisible(User $user, Project $project): Project
    {
        if ($this->canViewAll($user)) {
            return $project;
        }

        if ($project->assigned_pe_id === $user->id) {
            return $project;
        }

        throw (new ModelNotFoundException)->setModel(Project::class, [$project->getKey()]);
    }

    /**
     * A PE may mutate only his own project. Admin and Super Admin can see the
     * project but do not hold manage-own, so they receive 403 rather than 404.
     */
    public function ensureOwner(User $user, Project $project): Project
    {
        if ($project->assigned_pe_id !== $user->id) {
            if ($this->canViewAll($user)) {
                throw new AuthorizationException('This action is unauthorized.');
            }

            throw (new ModelNotFoundException)->setModel(Project::class, [$project->getKey()]);
        }

        if (! $this->authorization->hasPermission($user, PermissionName::PmmsProjectsManageOwn)) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        return $project;
    }
}
