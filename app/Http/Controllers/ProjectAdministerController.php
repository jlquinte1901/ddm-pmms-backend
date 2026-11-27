<?php

namespace App\Http\Controllers;

use App\Enums\RoleName;
use App\Http\Requests\CorrectPlannedDatesRequest;
use App\Http\Requests\DeleteProjectRequest;
use App\Http\Requests\ReassignProjectRequest;
use App\Http\Requests\ReopenProjectRequest;
use App\Http\Requests\RestoreProjectRequest;
use App\Http\Requests\TerminateProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\UserSummaryResource;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

final class ProjectAdministerController extends Controller
{
    /**
     * Active Project Engineers available for the reassign picker.
     */
    public function assignableEngineers(): AnonymousResourceCollection
    {
        $engineers = User::query()
            ->whereHas('roles', fn ($query) => $query->where('name', RoleName::ProjectEngineer->value))
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('full_name')
            ->get();

        return UserSummaryResource::collection($engineers);
    }

    public function correctPlannedDates(
        CorrectPlannedDatesRequest $request,
        Project $pmmsProject,
        ProjectService $projects,
    ): ProjectResource {
        return new ProjectResource($projects->correctPlannedDates(
            $pmmsProject,
            (string) $request->validated('planned_start_date'),
            (string) $request->validated('planned_finish_date'),
            (string) $request->validated('reason'),
            $request->user(),
        ));
    }

    public function terminate(
        TerminateProjectRequest $request,
        Project $pmmsProject,
        ProjectService $projects,
    ): ProjectResource {
        return new ProjectResource($projects->terminate(
            $pmmsProject,
            $request->validated('reason'),
            $request->user(),
        ));
    }

    public function reopen(
        ReopenProjectRequest $request,
        Project $pmmsProject,
        ProjectService $projects,
    ): ProjectResource {
        return new ProjectResource($projects->reopen(
            $pmmsProject,
            (int) $request->validated('progress_percentage'),
            $request->validated('reason'),
            $request->user(),
        ));
    }

    public function reassign(
        ReassignProjectRequest $request,
        Project $pmmsProject,
        ProjectService $projects,
    ): ProjectResource {
        return new ProjectResource($projects->reassign(
            $pmmsProject,
            (int) $request->validated('assigned_pe_id'),
            $request->validated('reason'),
            $request->user(),
        ));
    }

    public function destroy(
        DeleteProjectRequest $request,
        Project $pmmsProject,
        ProjectService $projects,
    ): Response {
        $projects->delete($pmmsProject, $request->validated('reason'), $request->user());

        return response()->noContent();
    }

    public function restore(
        RestoreProjectRequest $request,
        Project $pmmsDeletedProject,
        ProjectService $projects,
    ): ProjectResource {
        return new ProjectResource($projects->restore(
            $pmmsDeletedProject,
            $request->validated('reason'),
            $request->user(),
        ));
    }
}
