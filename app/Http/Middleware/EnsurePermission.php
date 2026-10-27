<?php

namespace App\Http\Middleware;

use App\Enums\PermissionName;
use App\Services\AuditService;
use App\Services\AuthorizationService;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsurePermission
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly AuditService $audit,
    ) {}

    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->is_active) {
            throw new AuthenticationException('Unauthenticated.');
        }

        $granted = false;

        foreach ($permissions as $permission) {
            if ($this->authorization->hasPermission($user, $permission)) {
                $granted = true;

                break;
            }
        }

        if (! $granted) {
            $primaryPermission = $permissions[0] ?? null;
            $context = [
                'method' => $request->method(),
                'path' => $request->path(),
            ];

            if (count($permissions) === 1) {
                $context['permission'] = $primaryPermission;
            } else {
                $context['permissions'] = $permissions;
            }

            $this->audit->record(
                module: $primaryPermission !== null
                    ? (PermissionName::tryFrom($primaryPermission)?->module() ?? 'shared')
                    : 'shared',
                action: 'authorization_denied',
                context: $context,
                actor: $user,
            );

            throw new AuthorizationException('This action is unauthorized.');
        }

        return $next($request);
    }
}
