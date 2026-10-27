<?php

namespace App\Http\Controllers;

use App\Http\Requests\DeleteProjectRequest;
use App\Http\Requests\ReassignProjectRequest;
use App\Http\Requests\ReopenProjectRequest;
use App\Http\Requests\RestoreProjectRequest;
use App\Http\Requests\TerminateProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Services\ProjectService;
use Illuminate\Http\Response;

final class ProjectAdministerController extends Controller
{
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
