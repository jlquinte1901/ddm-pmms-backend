<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRoleRequest;
use App\Http\Requests\UpdateRoleRequest;
use App\Http\Resources\RoleResource;
use App\Models\Role;
use App\Services\RoleService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

final class RoleController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min(
            max($request->integer('per_page', (int) config('api.pagination.default', 15)), 1),
            (int) config('api.pagination.max', 100),
        );

        return RoleResource::collection(
            Role::query()
                ->withCount('permissions')
                ->orderBy('name')
                ->paginate($perPage)
                ->withQueryString(),
        );
    }

    public function show(Role $role): RoleResource
    {
        $role->load('permissions');

        return new RoleResource($role);
    }

    public function store(StoreRoleRequest $request, RoleService $roles): RoleResource
    {
        $role = $roles->create($request->validated(), $request->user());
        $role->load('permissions');

        return new RoleResource($role);
    }

    public function update(UpdateRoleRequest $request, Role $role, RoleService $roles): RoleResource
    {
        $role = $roles->update($role, $request->validated(), $request->user());
        $role->load('permissions');

        return new RoleResource($role);
    }

    public function destroy(Request $request, Role $role, RoleService $roles): Response
    {
        $roles->delete($role, $request->user());

        return response()->noContent();
    }
}
