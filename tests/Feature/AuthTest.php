<?php

use App\Enums\PermissionName;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

test('login issues a token, returns effective permissions, and audits the login', function () {
    seedAccessControl();
    $user = User::factory()->admin()->create(['email' => 'admin@example.com']);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'ADMIN@example.com',
        'password' => 'password1234',
        'device_name' => 'phpunit',
    ]);

    $response->assertOk()
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('user.email', 'admin@example.com')
        ->assertJsonPath('user.role.name', 'admin')
        ->assertJsonFragment(['name' => PermissionName::DdmDocumentsUpload->value]);

    expect($response->json('token'))->toBeString()->not->toBeEmpty()
        ->and($response->json('expires_at'))->toBeString();

    $requestId = $response->headers->get('X-Request-ID');

    expect($requestId)->toBeString();

    $this->assertDatabaseHas('audit_logs', [
        'user_id' => $user->id,
        'module' => 'shared',
        'action' => 'login',
        'request_id' => $requestId,
    ]);

    expect($user->refresh()->last_login_at)->not->toBeNull();
});

test('login rejects wrong credentials and unknown emails with the same message', function () {
    seedAccessControl();
    $user = User::factory()->create(['email' => 'user@example.com']);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'user@example.com',
        'password' => 'wrong-password',
    ])->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonPath('errors.email.0', 'The provided credentials are incorrect.');

    $this->postJson('/api/v1/auth/login', [
        'email' => 'missing@example.com',
        'password' => 'wrong-password',
    ])->assertStatus(422)
        ->assertJsonPath('errors.email.0', 'The provided credentials are incorrect.');

    $this->assertDatabaseHas('audit_logs', [
        'user_id' => $user->id,
        'action' => 'login_failed',
    ]);
    $this->assertDatabaseHas('audit_logs', [
        'user_id' => null,
        'action' => 'login_failed',
    ]);
});

test('inactive and soft-deleted users cannot log in', function () {
    seedAccessControl();
    $inactive = User::factory()->inactive()->create(['email' => 'inactive@example.com']);
    $deleted = User::factory()->create(['email' => 'deleted@example.com']);
    $deleted->delete();

    $this->postJson('/api/v1/auth/login', [
        'email' => 'inactive@example.com',
        'password' => 'password1234',
    ])->assertStatus(422)
        ->assertJsonPath('errors.email.0', 'The provided credentials are incorrect.');

    $this->postJson('/api/v1/auth/login', [
        'email' => 'deleted@example.com',
        'password' => 'password1234',
    ])->assertStatus(422)
        ->assertJsonPath('errors.email.0', 'The provided credentials are incorrect.');

    expect(AuditLog::query()->where('action', 'login_failed')->where('user_id', $inactive->id)->firstOrFail()->context['reason'])
        ->toBe('inactive_account')
        ->and(AuditLog::query()->where('action', 'login_failed')->where('user_id', $deleted->id)->firstOrFail()->context['reason'])
        ->toBe('deleted_account');
});

test('login attempts are throttled per email and IP address', function () {
    seedAccessControl();
    User::factory()->create(['email' => 'throttle@example.com']);

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'throttle@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(422);
    }

    $this->postJson('/api/v1/auth/login', [
        'email' => 'throttle@example.com',
        'password' => 'wrong-password',
    ])->assertStatus(429)
        ->assertJsonPath('code', 'too_many_requests');

    $throttled = AuditLog::query()->latest('id')->firstOrFail();

    expect($throttled->action)->toBe('login_failed')
        ->and($throttled->context['reason'])->toBe('throttled');
});

test('authenticated users can read their own identity and permissions', function () {
    seedAccessControl();
    $user = User::factory()->projectEngineer()->create();
    $token = $user->createToken('phpunit', ['*'], now()->addHour());

    $this->withToken($token->plainTextToken)
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.email', $user->email)
        ->assertJsonPath('data.role.name', 'project_engineer')
        ->assertJsonFragment(['name' => PermissionName::PmmsProjectsManageOwn->value])
        ->assertJsonMissing(['name' => PermissionName::UsersManage->value]);
});

test('logout revokes only the current token and writes an audit row', function () {
    seedAccessControl();
    $user = User::factory()->superAdmin()->create();
    $token = $user->createToken('phpunit', ['*'], now()->addHour());

    $this->withToken($token->plainTextToken)
        ->postJson('/api/v1/auth/logout')
        ->assertNoContent();

    $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->accessToken->id]);
    $this->assertDatabaseHas('audit_logs', [
        'user_id' => $user->id,
        'action' => 'logout',
    ]);

    Auth::forgetGuards();

    $this->withToken($token->plainTextToken)
        ->getJson('/api/v1/auth/me')
        ->assertUnauthorized()
        ->assertJsonPath('code', 'unauthenticated');
});

test('expired tokens are rejected', function () {
    seedAccessControl();
    $user = User::factory()->viewer()->create();
    $token = $user->createToken('phpunit', ['*'], now()->addHour());

    $this->travel(2)->hours();

    $this->withToken($token->plainTextToken)
        ->getJson('/api/v1/auth/me')
        ->assertUnauthorized()
        ->assertJsonPath('code', 'unauthenticated');
});

test('an inactive user is rejected even when the token still exists', function () {
    seedAccessControl();
    $user = User::factory()->viewer()->create();
    $token = $user->createToken('phpunit', ['*'], now()->addHour());

    $user->forceFill(['is_active' => false])->save();

    $this->withToken($token->plainTextToken)
        ->getJson('/api/v1/auth/me')
        ->assertUnauthorized()
        ->assertJsonPath('code', 'unauthenticated');
});
