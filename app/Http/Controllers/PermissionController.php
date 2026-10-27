<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePermissionRequest;
use App\Http\Requests\UpdatePermissionRequest;
use App\Http\Resources\PermissionResource;
use App\Models\Permission;
use App\Services\PermissionService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

final class PermissionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min(
            max($request->integer('per_page', (int) config('api.pagination.default', 15)), 1),
            (int) config('api.pagination.max', 100),
        );

        return PermissionResource::collection(
            Permission::query()
                ->orderBy('module')
                ->orderBy('name')
                ->paginate($perPage)
                ->withQueryString(),
        );
    }

    public function show(Permission $permission): PermissionResource
    {
        return new PermissionResource($permission);
    }

    public function store(StorePermissionRequest $request, PermissionService $permissions): PermissionResource
    {
        return new PermissionResource(
            $permissions->create($request->validated(), $request->user()),
        );
    }

    public function update(
        UpdatePermissionRequest $request,
        Permission $permission,
        PermissionService $permissions,
    ): PermissionResource {
        return new PermissionResource(
            $permissions->update($permission, $request->validated(), $request->user()),
        );
    }

    public function destroy(Request $request, Permission $permission, PermissionService $permissions): Response
    {
        $permissions->delete($permission, $request->user());

        return response()->noContent();
    }
}
