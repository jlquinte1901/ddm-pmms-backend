<?php

namespace App\Http\Controllers;

use App\Enums\DocumentCategoryScope;
use App\Http\Requests\IndexDocumentCategoryRequest;
use App\Http\Requests\StoreDocumentCategoryRequest;
use App\Http\Requests\UpdateDocumentCategoryRequest;
use App\Http\Resources\DocumentCategoryResource;
use App\Models\DocumentCategory;
use App\Services\DocumentCategoryService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class DocumentCategoryController extends Controller
{
    public function index(IndexDocumentCategoryRequest $request): AnonymousResourceCollection
    {
        $perPage = min(
            max($request->integer('per_page', (int) config('api.pagination.default', 15)), 1),
            (int) config('api.pagination.max', 100),
        );

        $categories = DocumentCategory::query()
            ->where('scope', DocumentCategoryScope::Ddm->value)
            ->withCount('documents')
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

        return DocumentCategoryResource::collection($categories);
    }

    public function show(DocumentCategory $ddmCategory): DocumentCategoryResource
    {
        return new DocumentCategoryResource($ddmCategory->loadCount('documents'));
    }

    public function store(
        StoreDocumentCategoryRequest $request,
        DocumentCategoryService $categories,
    ): JsonResponse {
        $category = $categories->create($request->validated(), $request->user());

        return (new DocumentCategoryResource($category->loadCount('documents')))
            ->response()
            ->setStatusCode(201);
    }

    public function update(
        UpdateDocumentCategoryRequest $request,
        DocumentCategory $ddmCategory,
        DocumentCategoryService $categories,
    ): DocumentCategoryResource {
        $category = $categories->update($ddmCategory, $request->validated(), $request->user());

        return new DocumentCategoryResource($category->loadCount('documents'));
    }
}
