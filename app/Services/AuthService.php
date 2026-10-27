<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;

final class AuthService
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * @return array{user: User, token: NewAccessToken}
     *
     * @throws ValidationException
     */
    public function login(string $email, string $password, ?string $deviceName = null): array
    {
        $email = Str::lower(trim($email));
        $user = User::withTrashed()->where('email', $email)->first();
        $passwordMatches = $user !== null && Hash::check($password, $user->password);

        $reason = match (true) {
            $user === null => 'unknown_email',
            $user->trashed() => 'deleted_account',
            ! $user->is_active => 'inactive_account',
            ! $passwordMatches => 'invalid_password',
            default => null,
        };

        if ($reason !== null) {
            $this->audit->record(
                module: 'shared',
                action: 'login_failed',
                auditable: $user,
                context: ['email' => $email, 'reason' => $reason],
                userId: $user?->getKey(),
            );

            throw ValidationException::withMessages([
                'email' => 'The provided credentials are incorrect.',
            ]);
        }

        /** @var User $user */
        return DB::transaction(function () use ($user, $deviceName): array {
            $user->forceFill(['last_login_at' => now()])->save();

            $expiresAt = now()->addHours((int) config('auth.token_expiry_hours', 8));
            $token = $user->createToken($deviceName ?: 'api-token', ['*'], $expiresAt);

            $this->audit->record(
                module: 'shared',
                action: 'login',
                auditable: $user,
                newValues: ['last_login_at' => $user->last_login_at?->toIso8601String()],
                context: ['device_name' => $deviceName ?: 'api-token'],
                actor: $user,
            );

            return ['user' => $user, 'token' => $token];
        });
    }

    public function logout(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $user->currentAccessToken()?->delete();

            $this->audit->record(
                module: 'shared',
                action: 'logout',
                auditable: $user,
                actor: $user,
            );
        });
    }
}
