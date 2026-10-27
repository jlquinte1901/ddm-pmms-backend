<?php

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('the seeded permission matrix matches the confirmed role responsibilities', function () {
    seedAccessControl();

    expect(Permission::query()->count())->toBe(count(PermissionName::cases()));

    expect(roleNamed(RoleName::SuperAdmin)->permissions()->pluck('name')->all())
        ->toContain(PermissionName::UsersManage->value)
        ->toContain(PermissionName::DdmDocumentsDelete->value)
        ->toContain(PermissionName::PmmsProjectsAdminister->value)
        ->not->toContain(PermissionName::PmmsProjectsCreate->value);

    expect(roleNamed(RoleName::Admin)->permissions()->pluck('name')->all())
        ->toContain(PermissionName::DdmDownloadsApproveRestricted->value)
        ->toContain(PermissionName::PmmsProjectsAdminister->value)
        ->not->toContain(PermissionName::UsersManage->value)
        ->not->toContain(PermissionName::DdmDownloadsApproveConfidential->value)
        ->not->toContain(PermissionName::DdmDocumentsDelete->value);

    expect(roleNamed(RoleName::ProjectEngineer)->permissions()->pluck('name')->all())
        ->toContain(PermissionName::PmmsProjectsCreate->value)
        ->toContain(PermissionName::PmmsProjectsManageOwn->value)
        ->not->toContain(PermissionName::PmmsProjectsAdminister->value)
        ->not->toContain(PermissionName::DdmDocumentsUpload->value);

    expect(roleNamed(RoleName::Viewer)->permissions()->count())->toBe(0);
});

test('a super admin can list and inspect roles with their permissions', function () {
    seedAccessControl();
    $admin = User::factory()->superAdmin()->create();
    Sanctum::actingAs($admin);

    $this->getJson('/api/v1/roles')
        ->assertOk()
        ->assertJsonCount(4, 'data')
        ->assertJsonFragment(['name' => 'super_admin', 'is_system' => true]);

    $this->getJson('/api/v1/roles/'.roleNamed(RoleName::SuperAdmin)->id)
        ->assertOk()
        ->assertJsonPath('data.name', 'super_admin')
        ->assertJsonFragment(['name' => PermissionName::UsersManage->value]);
});

test('role and permission administration is restricted to super admins', function (RoleName $role, int $status) {
    seedAccessControl();
    $user = User::factory()->for(roleNamed($role))->create();
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/roles')->assertStatus($status);
    $this->getJson('/api/v1/permissions')->assertStatus($status);
})->with([
    'super admin' => [RoleName::SuperAdmin, 200],
    'admin' => [RoleName::Admin, 403],
    'project engineer' => [RoleName::ProjectEngineer, 403],
    'viewer' => [RoleName::Viewer, 403],
]);

test('a custom role can be created, updated, and deleted with audit rows', function () {
    seedAccessControl();
    $admin = User::factory()->superAdmin()->create();
    $permission = Permission::query()
        ->where('name', PermissionName::DdmReportsView->value)
        ->firstOrFail();
    Sanctum::actingAs($admin);

    $this->postJson('/api/v1/roles', [
        'name' => 'report_auditor',
        'label' => 'Report Auditor',
        'permissions' => [$permission->id],
    ])->assertCreated()
        ->assertJsonPath('data.name', 'report_auditor')
        ->assertJsonFragment(['name' => PermissionName::DdmReportsView->value]);

    $role = Role::query()->where('name', 'report_auditor')->firstOrFail();

    $this->assertDatabaseHas('permission_role', [
        'role_id' => $role->id,
        'permission_id' => $permission->id,
    ]);
    $this->assertDatabaseHas('audit_logs', [
        'user_id' => $admin->id,
        'action' => 'role_created',
        'auditable_id' => $role->id,
    ]);

    $this->patchJson("/api/v1/roles/{$role->id}", [
        'label' => 'Senior Report Auditor',
    ])->assertOk()
        ->assertJsonPath('data.label', 'Senior Report Auditor');

    $this->deleteJson("/api/v1/roles/{$role->id}")->assertNoContent();

    $this->assertDatabaseMissing('roles', ['id' => $role->id]);
    $this->assertDatabaseHas('audit_logs', [
        'user_id' => $admin->id,
        'action' => 'role_deleted',
        'auditable_id' => $role->id,
    ]);
});

test('system roles cannot be renamed or deleted', function () {
    seedAccessControl();
    $admin = User::factory()->superAdmin()->create();
    $role = roleNamed(RoleName::SuperAdmin);
    Sanctum::actingAs($admin);

    $this->patchJson("/api/v1/roles/{$role->id}", [
        'name' => 'root',
    ])->assertStatus(409)->assertJsonPath('code', 'state_conflict');

    $this->patchJson("/api/v1/roles/{$role->id}", [
        'label' => 'System Owner',
    ])->assertOk()->assertJsonPath('data.label', 'System Owner');

    $this->deleteJson("/api/v1/roles/{$role->id}")
        ->assertStatus(409)
        ->assertJsonPath('code', 'state_conflict');
});

test('a custom role assigned to a user cannot be deleted', function () {
    seedAccessControl();
    $admin = User::factory()->superAdmin()->create();
    $role = Role::factory()->create([
        'name' => 'limited_role',
        'label' => 'Limited Role',
    ]);
    User::factory()->for($role)->create();
    Sanctum::actingAs($admin);

    $this->deleteJson("/api/v1/roles/{$role->id}")
        ->assertStatus(409)
        ->assertJsonPath('code', 'state_conflict');
});

test('role permission changes take effect on the next request from the database', function () {
    seedAccessControl();
    $admin = User::factory()->superAdmin()->create();
    $role = Role::factory()->create([
        'name' => 'audit_reader',
        'label' => 'Audit Reader',
    ]);
    $user = User::factory()->for($role)->create();
    $permission = Permission::query()
        ->where('name', PermissionName::AuditView->value)
        ->firstOrFail();

    Sanctum::actingAs($user);
    $this->getJson('/api/v1/audit-logs')->assertForbidden();

    Sanctum::actingAs($admin);
    $this->putJson("/api/v1/roles/{$role->id}/permissions", [
        'permissions' => [$permission->id],
    ])->assertOk()
        ->assertJsonFragment(['name' => PermissionName::AuditView->value]);

    Sanctum::actingAs($user);
    $this->getJson('/api/v1/audit-logs')->assertOk();

    $this->assertDatabaseHas('audit_logs', [
        'user_id' => $admin->id,
        'action' => 'role_permissions_updated',
        'auditable_id' => $role->id,
    ]);
});

test('custom permissions can be managed but system permissions are immutable', function () {
    seedAccessControl();
    $admin = User::factory()->superAdmin()->create();
    $systemPermission = Permission::query()
        ->where('name', PermissionName::AuditView->value)
        ->firstOrFail();
    Sanctum::actingAs($admin);

    $this->postJson('/api/v1/permissions', [
        'name' => 'custom.reports.view',
        'module' => 'shared',
    ])->assertCreated()
        ->assertJsonPath('data.is_system', false);

    $customPermission = Permission::query()
        ->where('name', 'custom.reports.view')
        ->firstOrFail();

    $this->patchJson("/api/v1/permissions/{$customPermission->id}", [
        'module' => 'ddm',
    ])->assertOk()->assertJsonPath('data.module', 'ddm');

    $this->patchJson("/api/v1/permissions/{$systemPermission->id}", [
        'name' => 'audit.read',
    ])->assertStatus(409)->assertJsonPath('code', 'state_conflict');

    $this->deleteJson("/api/v1/permissions/{$systemPermission->id}")
        ->assertStatus(409)
        ->assertJsonPath('code', 'state_conflict');

    $this->deleteJson("/api/v1/permissions/{$customPermission->id}")->assertNoContent();

    $this->assertDatabaseMissing('permissions', ['id' => $customPermission->id]);
    $this->assertDatabaseHas('audit_logs', [
        'user_id' => $admin->id,
        'action' => 'permission_created',
        'auditable_id' => $customPermission->id,
    ]);
    $this->assertDatabaseHas('audit_logs', [
        'user_id' => $admin->id,
        'action' => 'permission_deleted',
        'auditable_id' => $customPermission->id,
    ]);
});

test('only a super admin can read the audit trail', function (RoleName $role, int $status) {
    seedAccessControl();
    $user = User::factory()->for(roleNamed($role))->create();
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/audit-logs')->assertStatus($status);
})->with([
    'super admin' => [RoleName::SuperAdmin, 200],
    'admin' => [RoleName::Admin, 403],
    'project engineer' => [RoleName::ProjectEngineer, 403],
    'viewer' => [RoleName::Viewer, 403],
]);

test('audit logs are filterable, readable, and not writable over the API', function () {
    seedAccessControl();
    $admin = User::factory()->superAdmin()->create();
    $log = AuditLog::factory()->create([
        'user_id' => $admin->id,
        'module' => 'shared',
        'action' => 'login',
    ]);
    Sanctum::actingAs($admin);

    $response = $this->getJson('/api/v1/audit-logs?action=login')
        ->assertOk()
        ->assertJsonPath('data.0.action', 'login')
        ->assertJsonPath('data.0.user.id', $admin->id);

    $logId = $response->json('data.0.id');

    $this->getJson("/api/v1/audit-logs/{$logId}")
        ->assertOk()
        ->assertJsonPath('data.user.email', $admin->email);

    $this->putJson("/api/v1/audit-logs/{$logId}", [])
        ->assertStatus(405)
        ->assertJsonPath('code', 'method_not_allowed');
});

test('audit entries retain actors who have been soft-deleted', function () {
    seedAccessControl();
    $actor = User::factory()->superAdmin()->create();
    $reader = User::factory()->superAdmin()->create();
    $log = AuditLog::factory()->create([
        'user_id' => $actor->id,
        'module' => 'shared',
        'action' => 'login',
    ]);

    $actor->delete();
    Sanctum::actingAs($reader);

    $this->getJson("/api/v1/audit-logs/{$log->id}")
        ->assertOk()
        ->assertJsonPath('data.user.id', $actor->id);

    $this->getJson('/api/v1/audit-logs?user_id='.$actor->id)
        ->assertOk()
        ->assertJsonCount(1, 'data');
});
