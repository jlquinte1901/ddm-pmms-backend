<?php

namespace App\Http\Controllers;

use App\Enums\ProjectStatus;
use App\Http\Requests\PauseProjectRequest;
use App\Http\Requests\ResumeProjectRequest;
use App\Http\Requests\StartProjectRequest;
use App\Http\Requests\StoreProjectProgressRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Services\ProjectAccessService;
use App\Services\ProjectService;

final class ProjectLifecycleController extends Controller
{
    public function start(
        StartProjectRequest $request,
        Project $pmmsProject,
        ProjectAccessService $access,
        ProjectService $projects,
    ): ProjectResource {
        $access->ensureOwner($request->user(), $pmmsProject);

        $project = $projects->start(
            $pmmsProject,
            $request->validated('remarks'),
            $request->user(),
        );

        return new ProjectResource($project);
    }

    public function progress(
        StoreProjectProgressRequest $request,
        Project $pmmsProject,
        ProjectAccessService $access,
        ProjectService $projects,
    ): ProjectResource {
        $access->ensureOwner($request->user(), $pmmsProject);

        $project = $projects->recordProgress(
            $pmmsProject,
            $request->validated(),
            $request->user(),
        );

        return new ProjectResource($project);
    }

    public function pause(
        PauseProjectRequest $request,
        Project $pmmsProject,
        ProjectAccessService $access,
        ProjectService $projects,
    ): ProjectResource {
        $access->ensureOwner($request->user(), $pmmsProject);

        $project = $projects->pause(
            $pmmsProject,
            ProjectStatus::from($request->validated('status')),
            $request->validated('reason'),
            $request->user(),
        );

        return new ProjectResource($project);
    }

    public function resume(
        ResumeProjectRequest $request,
        Project $pmmsProject,
        ProjectAccessService $access,
        ProjectService $projects,
    ): ProjectResource {
        $access->ensureOwner($request->user(), $pmmsProject);

        $project = $projects->resume(
            $pmmsProject,
            $request->validated('revised_finish_date'),
            $request->validated('remarks'),
            $request->user(),
        );

        return new ProjectResource($project);
    }
}
