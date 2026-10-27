<?php

namespace App\Http\Controllers;

use App\Http\Requests\IndexProjectCategoryRequest;
use App\Http\Requests\StoreProjectCategoryRequest;
use App\Http\Requests\UpdateProjectCategoryRequest;
use App\Http\Resources\ProjectCategoryResource;
use App\Models\ProjectCategory;
use App\Services\ProjectCategoryService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ProjectCategoryController extends Controller
{
    public function index(IndexProjectCategoryRequest $request): AnonymousResourceCollection
    {
        $perPage = min(
            max($request->integer('per_page', (int) config('api.pagination.default', 15)), 1),
            (int) config('api.pagination.max', 100),
        );

        $categories = ProjectCategory::query()
            ->withCount('projects')
            ->when(
                $request->has('is_active'),
                fn (Builder $query): Builder => $query->where('is_active', $request->boolean('is_active')),
            )
            ->when(
                $request->filled('q'),
                fn (Builder $query): Builder => $query->where('name', 'like', '%'.trim((string) $request->input('q')).'%'),
            )
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();

        return ProjectCategoryResource::collection($categories);
    }

    public function show(ProjectCategory $pmmsCategory): ProjectCategoryResource
    {
        return new ProjectCategoryResource($pmmsCategory->loadCount('projects'));
    }

    public function store(
        StoreProjectCategoryRequest $request,
        ProjectCategoryService $categories,
    ): JsonResponse {
        $category = $categories->create($request->validated(), $request->user());

        return (new ProjectCategoryResource($category->loadCount('projects')))
            ->response()
            ->setStatusCode(201);
    }

    public function update(
        UpdateProjectCategoryRequest $request,
        ProjectCategory $pmmsCategory,
        ProjectCategoryService $categories,
    ): ProjectCategoryResource {
        $category = $categories->update($pmmsCategory, $request->validated(), $request->user());

        return new ProjectCategoryResource($category->loadCount('projects'));
    }
}
