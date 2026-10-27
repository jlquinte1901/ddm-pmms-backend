<?php

namespace App\Http\Controllers;

use App\Http\Requests\IndexUserRequest;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\UserService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

final class UserController extends Controller
{
    public function index(IndexUserRequest $request): AnonymousResourceCollection
    {
        $query = User::query()->with('role');

        if ($request->boolean('trashed')) {
            $query->onlyTrashed();
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));

            $query->where(function ($builder) use ($search): void {
                $builder->where('full_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->filled('role_id')) {
            $query->where('role_id', $request->integer('role_id'));
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $perPage = $request->integer('per_page', (int) config('api.pagination.default', 15));

        return UserResource::collection(
            $query->orderBy('full_name')->paginate($perPage)->withQueryString(),
        );
    }

    public function show(User $user, AuthorizationService $authorization): UserResource
    {
        $user->load('role');
        $user->setRelation('effectivePermissions', $authorization->permissionsFor($user));

        return new UserResource($user);
    }

    public function store(
        StoreUserRequest $request,
        UserService $users,
        AuthorizationService $authorization,
    ): UserResource {
        $user = $users->create($request->validated(), $request->user());
        $user->load('role');
        $user->setRelation('effectivePermissions', $authorization->permissionsFor($user));

        return new UserResource($user);
    }

    public function update(
        UpdateUserRequest $request,
        User $user,
        UserService $users,
        AuthorizationService $authorization,
    ): UserResource {
        $user = $users->update($user, $request->validated(), $request->user());
        $user->load('role');
        $user->setRelation('effectivePermissions', $authorization->permissionsFor($user));

        return new UserResource($user);
    }

    public function destroy(Request $request, User $user, UserService $users): Response
    {
        $users->delete($user, $request->user());

        return response()->noContent();
    }

    public function restore(
        Request $request,
        User $user,
        UserService $users,
        AuthorizationService $authorization,
    ): UserResource {
        $user = $users->restore($user, $request->user());
        $user->load('role');
        $user->setRelation('effectivePermissions', $authorization->permissionsFor($user));

        return new UserResource($user);
    }

    public function activate(
        Request $request,
        User $user,
        UserService $users,
        AuthorizationService $authorization,
    ): UserResource {
        $user = $users->activate($user, $request->user());
        $user->load('role');
        $user->setRelation('effectivePermissions', $authorization->permissionsFor($user));

        return new UserResource($user);
    }

    public function deactivate(
        Request $request,
        User $user,
        UserService $users,
        AuthorizationService $authorization,
    ): UserResource {
        $user = $users->deactivate($user, $request->user());
        $user->load('role');
        $user->setRelation('effectivePermissions', $authorization->permissionsFor($user));

        return new UserResource($user);
    }
}
