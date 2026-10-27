<?php

namespace App\Providers;

use App\Models\User;
use App\Services\AuditService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('login', function (Request $request): Limit {
            $email = Str::lower(trim((string) $request->input('email')));

            return Limit::perMinute(max(1, (int) config('auth.login_throttle_per_minute', 5)))
                ->by(Str::transliterate($email.'|'.$request->ip()))
                ->response(function (Request $request, array $headers) use ($email): JsonResponse {
                    $user = $email !== ''
                        ? User::withTrashed()->where('email', $email)->first()
                        : null;

                    app(AuditService::class)->record(
                        module: 'shared',
                        action: 'login_failed',
                        auditable: $user,
                        context: ['email' => $email, 'reason' => 'throttled'],
                        userId: $user?->getKey(),
                    );

                    $response = response()->json([
                        'message' => 'Too many requests. Please try again later.',
                        'code' => 'too_many_requests',
                        'errors' => (object) [],
                    ], 429, $headers);

                    if ($requestId = $request->attributes->get('request_id')) {
                        $response->headers->set('X-Request-ID', (string) $requestId);
                    }

                    return $response;
                });
        });
    }
}
