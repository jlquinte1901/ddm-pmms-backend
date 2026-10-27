<?php

use App\Enums\DocumentAccessLevel;
use App\Enums\DocumentCategoryScope;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('DDM category writes are limited to category managers', function (RoleName $role, int $status) {
    seedAccessControl();
    $user = User::factory()->for(roleNamed($role))->create();
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/ddm/categories', [
        'name' => 'Category '.$role->value,
        'default_access_level' => DocumentAccessLevel::Open->value,
    ])->assertStatus($status);

    if ($status === 201) {
        $this->assertDatabaseHas('document_categories', [
            'name' => 'Category '.$role->value,
            'scope' => DocumentCategoryScope::Ddm->value,
        ]);
    }
})->with([
    'super admin' => [RoleName::SuperAdmin, 201],
    'admin' => [RoleName::Admin, 201],
    'project engineer' => [RoleName::ProjectEngineer, 403],
    'viewer' => [RoleName::Viewer, 403],
]);

test('DDM category reads require DDM document view access', function (RoleName $role, int $status) {
    seedAccessControl();
    $user = User::factory()->for(roleNamed($role))->create();
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/ddm/categories')->assertStatus($status);
})->with([
    'super admin' => [RoleName::SuperAdmin, 200],
    'admin' => [RoleName::Admin, 200],
    'project engineer' => [RoleName::ProjectEngineer, 200],
    'viewer' => [RoleName::Viewer, 403],
]);

test('a viewer granted DDM document view can read categories', function () {
    seedAccessControl();
    $admin = User::factory()->superAdmin()->create();
    $viewer = User::factory()->viewer()->create();
    $permission = Permission::query()
        ->where('name', PermissionName::DdmDocumentsView->value)
        ->firstOrFail();

    $viewer->grantedPermissions()->attach($permission, ['granted_by' => $admin->id]);

    Sanctum::actingAs($viewer);

    $this->getJson('/api/v1/ddm/categories')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('a DDM category is created with a forced DDM scope and an audit row', function () {
    seedAccessControl();
    $admin = User::factory()->admin()->create();
    Sanctum::actingAs($admin);

    $this->postJson('/api/v1/ddm/categories', [
        'name' => 'Memoranda',
        'default_access_level' => DocumentAccessLevel::Restricted->value,
        'scope' => DocumentCategoryScope::Pmms->value,
    ])->assertCreated()
        ->assertJsonPath('data.name', 'Memoranda')
        ->assertJsonPath('data.scope', DocumentCategoryScope::Ddm->value)
        ->assertJsonPath('data.default_access_level', DocumentAccessLevel::Restricted->value)
        ->assertJsonPath('data.is_active', true)
        ->assertJsonPath('data.documents_count', 0);

    $category = DocumentCategory::query()->where('name', 'Memoranda')->firstOrFail();

    expect($category->scope)->toBe(DocumentCategoryScope::Ddm)
        ->and($category->default_access_level)->toBe(DocumentAccessLevel::Restricted)
        ->and($category->is_active)->toBeTrue();

    $this->assertDatabaseHas('audit_logs', [
        'user_id' => $admin->id,
        'module' => 'ddm',
        'action' => 'category_created',
        'auditable_type' => DocumentCategory::class,
        'auditable_id' => $category->id,
    ]);
});

test('a DDM category can be updated and deactivated without changing its scope', function () {
    seedAccessControl();
    $admin = User::factory()->admin()->create();
    $category = DocumentCategory::factory()->create(['name' => 'Old Records']);
    Sanctum::actingAs($admin);

    $this->patchJson("/api/v1/ddm/categories/{$category->id}", [
        'name' => 'Archived Records',
        'default_access_level' => DocumentAccessLevel::Confidential->value,
        'is_active' => false,
        'scope' => DocumentCategoryScope::Pmms->value,
    ])->assertOk()
        ->assertJsonPath('data.name', 'Archived Records')
        ->assertJsonPath('data.scope', DocumentCategoryScope::Ddm->value)
        ->assertJsonPath('data.default_access_level', DocumentAccessLevel::Confidential->value)
        ->assertJsonPath('data.is_active', false);

    $category->refresh();

    expect($category->scope)->toBe(DocumentCategoryScope::Ddm)
        ->and($category->is_active)->toBeFalse();

    $audit = AuditLog::query()
        ->where('action', 'category_updated')
        ->latest('id')
        ->firstOrFail();

    expect($audit->old_values['name'])->toBe('Old Records')
        ->and($audit->new_values['name'])->toBe('Archived Records')
        ->and($audit->new_values['is_active'])->toBeFalse();
});

test('the DDM category list is scoped to DDM and supports state and name filters', function () {
    seedAccessControl();
    $admin = User::factory()->admin()->create();
    $open = DocumentCategory::factory()->create(['name' => 'Administrative Records']);
    DocumentCategory::factory()->inactive()->create(['name' => 'Superseded Records']);
    DocumentCategory::factory()->pmms()->create(['name' => 'Project Plans']);
    Document::factory()->create(['category_id' => $open->id]);

    Sanctum::actingAs($admin);

    $response = $this->getJson('/api/v1/ddm/categories')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonMissing(['name' => 'Project Plans']);

    $data = collect($response->json('data'))->keyBy('name');

    expect($data['Administrative Records']['documents_count'])->toBe(1)
        ->and($data['Administrative Records']['scope'])->toBe(DocumentCategoryScope::Ddm->value);

    $this->getJson('/api/v1/ddm/categories?is_active=0')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Superseded Records');

    $this->getJson('/api/v1/ddm/categories?q=Admin')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Administrative Records');
});

test('PMMS categories are not visible through DDM category routes', function () {
    seedAccessControl();
    $admin = User::factory()->admin()->create();
    $category = DocumentCategory::factory()->pmms()->create(['name' => 'Project Plans']);
    Sanctum::actingAs($admin);

    $this->getJson("/api/v1/ddm/categories/{$category->id}")
        ->assertNotFound()
        ->assertJsonPath('code', 'not_found');

    $this->patchJson("/api/v1/ddm/categories/{$category->id}", [
        'name' => 'Changed',
    ])->assertNotFound()
        ->assertJsonPath('code', 'not_found');
});

test('category validation rejects missing, duplicate, and invalid values', function () {
    seedAccessControl();
    $admin = User::factory()->admin()->create();
    DocumentCategory::factory()->create(['name' => 'Existing Category']);
    DocumentCategory::factory()->pmms()->create(['name' => 'Shared Name']);
    Sanctum::actingAs($admin);

    $this->postJson('/api/v1/ddm/categories', [])
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonStructure(['errors' => ['name', 'default_access_level']]);

    $this->postJson('/api/v1/ddm/categories', [
        'name' => 'Existing Category',
        'default_access_level' => DocumentAccessLevel::Open->value,
    ])->assertStatus(422)
        ->assertJsonStructure(['errors' => ['name']]);

    $this->postJson('/api/v1/ddm/categories', [
        'name' => 'Shared Name',
        'default_access_level' => DocumentAccessLevel::Open->value,
    ])->assertStatus(422)
        ->assertJsonStructure(['errors' => ['name']]);

    $this->postJson('/api/v1/ddm/categories', [
        'name' => 'New Category',
        'default_access_level' => 'public',
    ])->assertStatus(422)
        ->assertJsonStructure(['errors' => ['default_access_level']]);
});

test('DDM categories cannot be hard-deleted through the API', function () {
    seedAccessControl();
    $admin = User::factory()->admin()->create();
    $category = DocumentCategory::factory()->create();
    Sanctum::actingAs($admin);

    $this->deleteJson("/api/v1/ddm/categories/{$category->id}")
        ->assertStatus(405)
        ->assertJsonPath('code', 'method_not_allowed');

    $this->assertDatabaseHas('document_categories', ['id' => $category->id]);
});
