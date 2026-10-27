<?php

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('a super admin can create a user with an explicit role and audited hashed password', function () {
    seedAccessControl();
    $admin = User::factory()->superAdmin()->create();
    Sanctum::actingAs($admin);

    $response = $this->postJson('/api/v1/users', [
        'full_name' => 'Maria Santos',
        'email' => 'MARIA@example.com',
        'password' => 'long-enough-password',
        'password_confirmation' => 'long-enough-password',
        'role_id' => roleNamed(RoleName::Viewer)->id,
        'section' => 'Data Management',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.email', 'maria@example.com')
        ->assertJsonPath('data.role.name', 'viewer')
        ->assertJsonPath('data.permissions', []);

    $created = User::query()->where('email', 'maria@example.com')->firstOrFail();

    expect(Hash::check('long-enough-password', $created->password))->toBeTrue();

    $this->assertDatabaseHas('audit_logs', [
        'user_id' => $admin->id,
        'action' => 'user_created',
        'auditable_type' => User::class,
        'auditable_id' => $created->id,
    ]);
});

test('user creation validates the confirmed password and role rules', function () {
    seedAccessControl();
    $admin = User::factory()->superAdmin()->create();
    Sanctum::actingAs($admin);

    $this->postJson('/api/v1/users', [
        'full_name' => 'Short Password',
        'email' => 'short@example.com',
        'password' => 'short',
        'password_confirmation' => 'short',
        'role_id' => roleNamed(RoleName::Viewer)->id,
    ])->assertStatus(422)->assertJsonValidationErrors('password');

    $this->postJson('/api/v1/users', [
        'full_name' => 'Unknown Role',
        'email' => 'unknown@example.com',
        'password' => 'long-enough-password',
        'password_confirmation' => 'long-enough-password',
        'role_id' => 999999,
    ])->assertStatus(422)->assertJsonValidationErrors('role_id');

    User::factory()->create(['email' => 'taken@example.com']);

    $this->postJson('/api/v1/users', [
        'full_name' => 'Duplicate Email',
        'email' => 'taken@example.com',
        'password' => 'long-enough-password',
        'password_confirmation' => 'long-enough-password',
        'role_id' => roleNamed(RoleName::Viewer)->id,
    ])->assertStatus(422)->assertJsonValidationErrors('email');
});

test('only the super admin can use shared user administration', function (RoleName $role, int $status) {
    seedAccessControl();
    $user = User::factory()->for(roleNamed($role))->create();
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/users')->assertStatus($status);
})->with([
    'super admin' => [RoleName::SuperAdmin, 200],
    'admin' => [RoleName::Admin, 403],
    'project engineer' => [RoleName::ProjectEngineer, 403],
    'viewer' => [RoleName::Viewer, 403],
]);

test('a user detail includes their role and effective role permissions', function () {
    seedAccessControl();
    $admin = User::factory()->superAdmin()->create();
    $engineer = User::factory()->projectEngineer()->create();
    Sanctum::actingAs($admin);

    $this->getJson("/api/v1/users/{$engineer->id}")
        ->assertOk()
        ->assertJsonPath('data.email', $engineer->email)
        ->assertJsonPath('data.role.name', 'project_engineer')
        ->assertJsonFragment(['name' => PermissionName::PmmsProjectsCreate->value])
        ->assertJsonMissing(['name' => PermissionName::UsersManage->value]);
});

test('changing a role or password revokes the target user tokens', function () {
    seedAccessControl();
    $admin = User::factory()->superAdmin()->create();
    $target = User::factory()->viewer()->create();
    $token = $target->createToken('phpunit', ['*'], now()->addHour());
    Sanctum::actingAs($admin);

    $this->patchJson("/api/v1/users/{$target->id}", [
        'role_id' => roleNamed(RoleName::ProjectEngineer)->id,
    ])->assertOk()->assertJsonPath('data.role.name', 'project_engineer');

    $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->accessToken->id]);

    $secondToken = $target->createToken('phpunit-2', ['*'], now()->addHour());

    $this->patchJson("/api/v1/users/{$target->id}", [
        'password' => 'another-long-password',
        'password_confirmation' => 'another-long-password',
    ])->assertOk();

    $this->assertDatabaseMissing('personal_access_tokens', ['id' => $secondToken->accessToken->id]);
});

test('a super admin cannot change their own role', function () {
    seedAccessControl();
    $admin = User::factory()->superAdmin()->create();
    Sanctum::actingAs($admin);

    $this->patchJson("/api/v1/users/{$admin->id}", [
        'role_id' => roleNamed(RoleName::Viewer)->id,
    ])->assertStatus(409)
        ->assertJsonPath('code', 'state_conflict');
});

test('deactivation revokes tokens, blocks login, and can be reversed', function () {
    seedAccessControl();
    $admin = User::factory()->superAdmin()->create();
    $target = User::factory()->viewer()->create();
    $token = $target->createToken('phpunit', ['*'], now()->addHour());
    Sanctum::actingAs($admin);

    $this->postJson("/api/v1/users/{$target->id}/deactivate")
        ->assertOk()
        ->assertJsonPath('data.is_active', false);

    $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->accessToken->id]);

    $this->postJson('/api/v1/auth/login', [
        'email' => $target->email,
        'password' => 'password1234',
    ])->assertStatus(422);

    $this->postJson("/api/v1/users/{$target->id}/activate")
        ->assertOk()
        ->assertJsonPath('data.is_active', true);

    $this->postJson('/api/v1/auth/login', [
        'email' => $target->email,
        'password' => 'password1234',
    ])->assertOk();
});

test('deletion is soft, revokes tokens, and can be restored with audit rows', function () {
    seedAccessControl();
    $admin = User::factory()->superAdmin()->create();
    $target = User::factory()->viewer()->create();
    $token = $target->createToken('phpunit', ['*'], now()->addHour());
    Sanctum::actingAs($admin);

    $this->deleteJson("/api/v1/users/{$target->id}")->assertNoContent();

    $this->assertSoftDeleted('users', ['id' => $target->id]);
    $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->accessToken->id]);
    $this->assertDatabaseHas('audit_logs', [
        'user_id' => $admin->id,
        'action' => 'user_deleted',
        'auditable_id' => $target->id,
    ]);

    $this->postJson("/api/v1/users/{$target->id}/restore")
        ->assertOk()
        ->assertJsonPath('data.deleted_at', null);

    $this->assertNotSoftDeleted('users', ['id' => $target->id]);
    $this->assertDatabaseHas('audit_logs', [
        'user_id' => $admin->id,
        'action' => 'user_restored',
        'auditable_id' => $target->id,
    ]);

    $this->postJson("/api/v1/users/{$target->id}/restore")
        ->assertStatus(409)
        ->assertJsonPath('code', 'state_conflict');
});

test('a super admin cannot deactivate or delete their own account', function () {
    seedAccessControl();
    $admin = User::factory()->superAdmin()->create();
    Sanctum::actingAs($admin);

    $this->postJson("/api/v1/users/{$admin->id}/deactivate")
        ->assertStatus(409)
        ->assertJsonPath('code', 'state_conflict');

    $this->deleteJson("/api/v1/users/{$admin->id}")
        ->assertStatus(409)
        ->assertJsonPath('code', 'state_conflict');
});

test('per-user grants add permissions and are audited', function () {
    seedAccessControl();
    $admin = User::factory()->superAdmin()->create();
    $viewer = User::factory()->viewer()->create();
    $permission = Permission::query()
        ->where('name', PermissionName::DdmDocumentsView->value)
        ->firstOrFail();
    Sanctum::actingAs($admin);

    $this->putJson("/api/v1/users/{$viewer->id}/permissions", [
        'permissions' => [$permission->id],
    ])->assertOk()
        ->assertJsonFragment(['name' => PermissionName::DdmDocumentsView->value]);

    $this->assertDatabaseHas('permission_user', [
        'user_id' => $viewer->id,
        'permission_id' => $permission->id,
        'granted_by' => $admin->id,
    ]);
    $this->assertDatabaseHas('audit_logs', [
        'user_id' => $admin->id,
        'action' => 'user_permissions_updated',
        'auditable_id' => $viewer->id,
    ]);

    $token = $viewer->createToken('phpunit', ['*'], now()->addHour());

    $this->withToken($token->plainTextToken)
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonFragment(['name' => PermissionName::DdmDocumentsView->value]);

    $this->putJson("/api/v1/users/{$viewer->id}/permissions", ['permissions' => []])
        ->assertOk()
        ->assertJsonPath('data.permissions', []);

    $this->assertDatabaseMissing('permission_user', [
        'user_id' => $viewer->id,
        'permission_id' => $permission->id,
    ]);
});

test('user lists are filtered and paginated on the server', function () {
    seedAccessControl();
    $admin = User::factory()->superAdmin()->create();
    $engineerRole = roleNamed(RoleName::ProjectEngineer);
    $alice = User::factory()->for($engineerRole)->create(['full_name' => 'Alice Engineer']);
    User::factory()->viewer()->create(['full_name' => 'Bob Viewer']);
    $deleted = User::factory()->viewer()->create(['full_name' => 'Deleted User']);
    $deleted->delete();
    Sanctum::actingAs($admin);

    $this->getJson('/api/v1/users?search=Alice')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $alice->id);

    $this->getJson('/api/v1/users?role_id='.$engineerRole->id)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.full_name', 'Alice Engineer');

    $this->getJson('/api/v1/users?trashed=1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $deleted->id);

    $this->getJson('/api/v1/users?is_active=0')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});
