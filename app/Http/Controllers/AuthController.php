<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use App\Services\AuthorizationService;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class AuthController extends Controller
{
    public function login(
        LoginRequest $request,
        AuthService $auth,
        AuthorizationService $authorization,
    ): JsonResponse {
        $result = $auth->login(
            (string) $request->string('email'),
            (string) $request->string('password'),
            $request->input('device_name'),
        );

        $user = $result['user']->load('role');
        $user->setRelation('effectivePermissions', $authorization->permissionsFor($user));

        return response()->json([
            'token' => $result['token']->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $result['token']->accessToken->expires_at?->toIso8601String(),
            'user' => new UserResource($user),
        ]);
    }

    public function logout(Request $request, AuthService $auth): Response
    {
        $auth->logout($request->user());

        return response()->noContent();
    }

    public function me(Request $request, AuthorizationService $authorization): UserResource
    {
        $user = $request->user()->load('role');
        $user->setRelation('effectivePermissions', $authorization->permissionsFor($user));

        return new UserResource($user);
    }
}
