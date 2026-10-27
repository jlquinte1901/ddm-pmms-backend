<?php

namespace App\Http\Controllers;

use App\Http\Requests\IndexProjectHistoryRequest;
use App\Http\Requests\IndexProjectRequest;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Http\Resources\ProjectHistoryResource;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\ProjectUpdateResource;
use App\Models\Project;
use App\Models\ProjectHistory;
use App\Models\ProjectUpdate;
use App\Services\ProjectAccessService;
use App\Services\ProjectFilter;
use App\Services\ProjectQueryService;
use App\Services\ProjectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ProjectController extends Controller
{
    public function index(
        IndexProjectRequest $request,
        ProjectQueryService $queries,
    ): AnonymousResourceCollection {
        $filter = ProjectFilter::fromArray($request->validated());
        $perPage = min(
            max($request->integer('per_page', (int) config('api.pagination.default', 15)), 1),
            (int) config('api.pagination.max', 100),
        );

        $projects = $queries->paginate(
            $request->user(),
            $filter,
            $request->string('sort', 'created_at')->toString(),
            $request->string('direction', 'desc')->toString(),
            $perPage,
        );

        return ProjectResource::collection($projects);
    }

    public function show(
        Request $request,
        Project $pmmsProject,
        ProjectAccessService $access,
    ): ProjectResource {
        $access->ensureVisible($request->user(), $pmmsProject);

        return new ProjectResource($this->loadProject($pmmsProject));
    }

    public function store(
        StoreProjectRequest $request,
        ProjectService $projects,
    ): JsonResponse {
        $project = $projects->create($request->validated(), $request->user());

        return (new ProjectResource($project))
            ->response()
            ->setStatusCode(201);
    }

    public function update(
        UpdateProjectRequest $request,
        Project $pmmsProject,
        ProjectAccessService $access,
        ProjectService $projects,
    ): ProjectResource {
        $access->ensureOwner($request->user(), $pmmsProject);

        $project = $projects->update($pmmsProject, $request->validated(), $request->user());

        return new ProjectResource($project);
    }

    public function history(
        IndexProjectHistoryRequest $request,
        Project $pmmsProject,
        ProjectAccessService $access,
    ): AnonymousResourceCollection {
        $access->ensureVisible($request->user(), $pmmsProject);

        $perPage = min(
            max($request->integer('per_page', (int) config('api.pagination.default', 15)), 1),
            (int) config('api.pagination.max', 100),
        );

        $history = ProjectHistory::query()
            ->where('project_id', $pmmsProject->getKey())
            ->with('user')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        return ProjectHistoryResource::collection($history);
    }

    public function updates(
        IndexProjectHistoryRequest $request,
        Project $pmmsProject,
        ProjectAccessService $access,
    ): AnonymousResourceCollection {
        $access->ensureVisible($request->user(), $pmmsProject);

        $perPage = min(
            max($request->integer('per_page', (int) config('api.pagination.default', 15)), 1),
            (int) config('api.pagination.max', 100),
        );

        $updates = ProjectUpdate::query()
            ->where('project_id', $pmmsProject->getKey())
            ->with('reporter')
            ->orderByDesc('report_date')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        return ProjectUpdateResource::collection($updates);
    }

    private function loadProject(Project $project): Project
    {
        return $project->load(['category', 'assignedPe', 'creator']);
    }
}
