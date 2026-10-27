<?php

use App\Enums\DocumentAccessLevel;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\DocumentDownloadRequest;
use App\Models\DocumentVersion;
use App\Models\Permission;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function ddmPdfContent(): string
{
    return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [] /Count 0 >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF";
}

function ddmPdfUpload(string $name = 'document.pdf', ?string $content = null): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $content ?? ddmPdfContent());
}

function ddmDocumentRecord(DocumentCategory $category, User $creator, array $attributes = []): Document
{
    $document = Document::factory()->create(array_merge([
        'category_id' => $category->id,
        'created_by' => $creator->id,
    ], $attributes));

    DocumentVersion::factory()->create([
        'document_id' => $document->id,
        'uploaded_by' => $creator->id,
        'version_number' => 1,
    ]);

    return $document;
}

test('an admin can upload a DDM document with a private first version', function () {
    seedAccessControl();
    Storage::fake('local');

    $admin = User::factory()->admin()->create();
    $category = DocumentCategory::factory()->create([
        'name' => 'Budget Records',
        'default_access_level' => DocumentAccessLevel::Open,
    ]);

    Sanctum::actingAs($admin);

    $file = ddmPdfUpload('Annual Budget.pdf');

    $response = $this->post('/api/v1/ddm/documents', [
        'category_id' => $category->id,
        'title' => 'Annual Budget',
        'reference_no' => 'REF-2026-001',
        'document_date' => '2026-01-15',
        'description' => 'Annual budget records.',
        'file' => $file,
    ], ['Accept' => 'application/json']);

    $response->assertCreated()
        ->assertJsonPath('data.title', 'Annual Budget')
        ->assertJsonPath('data.state', Document::STATE_ACTIVE)
        ->assertJsonPath('data.effective_access_level', DocumentAccessLevel::Open->value)
        ->assertJsonPath('data.latest_version_number', 1)
        ->assertJsonPath('data.latest_version.original_name', 'Annual Budget.pdf')
        ->assertJsonPath('data.can_download', true)
        ->assertJsonMissingPath('data.latest_version.storage_path');

    $document = Document::query()->firstOrFail();
    $version = DocumentVersion::query()->firstOrFail();

    expect($document->project_id)->toBeNull()
        ->and($document->created_by)->toBe($admin->id)
        ->and($version->uploaded_by)->toBe($admin->id)
        ->and($version->checksum_sha256)->toBe(hash('sha256', ddmPdfContent()))
        ->and($version->storage_path)->not->toContain('Annual Budget.pdf');

    Storage::disk('local')->assertExists($version->storage_path);

    $this->assertDatabaseHas('audit_logs', [
        'user_id' => $admin->id,
        'module' => 'ddm',
        'action' => 'document_created',
        'auditable_type' => Document::class,
        'auditable_id' => $document->id,
    ]);
});

test('document uploads are limited to users with upload permission', function (RoleName $role, int $status) {
    seedAccessControl();
    Storage::fake('local');

    $user = User::factory()->for(roleNamed($role))->create();
    $category = DocumentCategory::factory()->create(['name' => 'Upload '.$role->value]);

    Sanctum::actingAs($user);

    $this->post('/api/v1/ddm/documents', [
        'category_id' => $category->id,
        'title' => 'Upload Test',
        'file' => ddmPdfUpload(),
    ], ['Accept' => 'application/json'])->assertStatus($status);
})->with([
    'super admin' => [RoleName::SuperAdmin, 201],
    'admin' => [RoleName::Admin, 201],
    'project engineer' => [RoleName::ProjectEngineer, 403],
    'viewer' => [RoleName::Viewer, 403],
]);

test('document uploads reject unsupported, mismatched, and oversized files', function () {
    seedAccessControl();
    Storage::fake('local');
    config(['documents.max_size_kb' => 1]);

    $admin = User::factory()->admin()->create();
    $category = DocumentCategory::factory()->create();
    Sanctum::actingAs($admin);

    $payload = [
        'category_id' => $category->id,
        'title' => 'Invalid File',
    ];

    $this->post('/api/v1/ddm/documents', $payload + [
        'file' => UploadedFile::fake()->createWithContent('script.exe', 'MZ'),
    ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['file']]);

    $this->post('/api/v1/ddm/documents', $payload + [
        'file' => UploadedFile::fake()->createWithContent('fake.pdf', 'plain text'),
    ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['file']]);

    $this->post('/api/v1/ddm/documents', $payload + [
        'file' => UploadedFile::fake()->createWithContent('large.pdf', ddmPdfContent().str_repeat('x', 2048)),
    ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['file']]);

    expect(Storage::disk('local')->allFiles('documents'))->toBe([]);
});

test('a new version is appended and the document latest version advances', function () {
    seedAccessControl();
    Storage::fake('local');

    $admin = User::factory()->admin()->create();
    $category = DocumentCategory::factory()->create();
    Sanctum::actingAs($admin);

    $documentId = $this->post('/api/v1/ddm/documents', [
        'category_id' => $category->id,
        'title' => 'Versioned Document',
        'file' => ddmPdfUpload('v1.pdf'),
    ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

    $firstVersion = DocumentVersion::query()
        ->where('document_id', $documentId)
        ->where('version_number', 1)
        ->firstOrFail();

    $this->post("/api/v1/ddm/documents/{$documentId}/versions", [
        'file' => ddmPdfUpload('v2.pdf', ddmPdfContent()."\n% revised"),
        'change_note' => 'Corrected page 2.',
    ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('data.version_number', 2)
        ->assertJsonPath('data.change_note', 'Corrected page 2.');

    $document = Document::query()->findOrFail($documentId);
    $secondVersion = DocumentVersion::query()
        ->where('document_id', $documentId)
        ->where('version_number', 2)
        ->firstOrFail();

    expect($document->latest_version_number)->toBe(2)
        ->and($secondVersion->storage_path)->not->toBe($firstVersion->storage_path);

    Storage::disk('local')->assertExists($firstVersion->storage_path);
    Storage::disk('local')->assertExists($secondVersion->storage_path);

    $this->assertDatabaseHas('audit_logs', [
        'user_id' => $admin->id,
        'module' => 'ddm',
        'action' => 'version_uploaded',
        'auditable_id' => $documentId,
    ]);
});

test('an identical version is rejected and its stored copy is removed', function () {
    seedAccessControl();
    Storage::fake('local');

    $admin = User::factory()->admin()->create();
    $category = DocumentCategory::factory()->create();
    Sanctum::actingAs($admin);

    $documentId = $this->post('/api/v1/ddm/documents', [
        'category_id' => $category->id,
        'title' => 'Duplicate Version',
        'file' => ddmPdfUpload('v1.pdf'),
    ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

    $filesBefore = Storage::disk('local')->allFiles('documents');

    $this->post("/api/v1/ddm/documents/{$documentId}/versions", [
        'file' => ddmPdfUpload('v2.pdf'),
    ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['file']]);

    expect(Storage::disk('local')->allFiles('documents'))->toBe($filesBefore)
        ->and(DocumentVersion::query()->where('document_id', $documentId)->count())->toBe(1);
});

test('an admin can archive and unarchive a document and archived documents reject versions', function () {
    seedAccessControl();
    Storage::fake('local');

    $admin = User::factory()->admin()->create();
    $category = DocumentCategory::factory()->create();
    Sanctum::actingAs($admin);

    $documentId = $this->post('/api/v1/ddm/documents', [
        'category_id' => $category->id,
        'title' => 'Archived Document',
        'file' => ddmPdfUpload('v1.pdf'),
    ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

    $this->postJson("/api/v1/ddm/documents/{$documentId}/archive")
        ->assertOk()
        ->assertJsonPath('data.state', Document::STATE_ARCHIVED)
        ->assertJsonPath('data.is_archived', true);

    $this->post("/api/v1/ddm/documents/{$documentId}/versions", [
        'file' => ddmPdfUpload('v2.pdf', ddmPdfContent()."\n% v2"),
    ], ['Accept' => 'application/json'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'state_conflict');

    $this->getJson('/api/v1/ddm/documents')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->getJson('/api/v1/ddm/documents?state=archived')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.state', Document::STATE_ARCHIVED);

    $this->postJson("/api/v1/ddm/documents/{$documentId}/unarchive")
        ->assertOk()
        ->assertJsonPath('data.state', Document::STATE_ACTIVE);

    $this->assertDatabaseHas('audit_logs', [
        'module' => 'ddm',
        'action' => 'document_archived',
        'auditable_id' => $documentId,
    ]);

    $this->assertDatabaseHas('audit_logs', [
        'module' => 'ddm',
        'action' => 'document_unarchived',
        'auditable_id' => $documentId,
    ]);
});

test('document archiving is limited to users with archive permission', function (RoleName $role, int $status) {
    seedAccessControl();

    $user = User::factory()->for(roleNamed($role))->create();
    $category = DocumentCategory::factory()->create();
    $document = ddmDocumentRecord($category, $user);

    Sanctum::actingAs($user);

    $this->postJson("/api/v1/ddm/documents/{$document->id}/archive")->assertStatus($status);
})->with([
    'super admin' => [RoleName::SuperAdmin, 200],
    'admin' => [RoleName::Admin, 200],
    'project engineer' => [RoleName::ProjectEngineer, 403],
    'viewer' => [RoleName::Viewer, 403],
]);

test('only a super admin can soft-delete and restore a document', function () {
    seedAccessControl();

    $admin = User::factory()->admin()->create();
    $superAdmin = User::factory()->superAdmin()->create();
    $category = DocumentCategory::factory()->create();
    $document = ddmDocumentRecord($category, $admin, ['title' => 'Disposable Document']);

    Sanctum::actingAs($admin);

    $this->deleteJson("/api/v1/ddm/documents/{$document->id}", [
        'reason' => 'Duplicate record.',
    ])->assertForbidden();

    Sanctum::actingAs($superAdmin);

    $this->deleteJson("/api/v1/ddm/documents/{$document->id}", [
        'reason' => 'Duplicate record.',
    ])->assertNoContent();

    $this->assertSoftDeleted('documents', ['id' => $document->id]);

    $this->getJson('/api/v1/ddm/documents')
        ->assertOk()
        ->assertJsonMissing(['id' => $document->id]);

    $this->getJson('/api/v1/ddm/documents?state=deleted')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $document->id)
        ->assertJsonPath('data.0.state', Document::STATE_DELETED);

    $this->postJson("/api/v1/ddm/documents/{$document->id}/restore", [
        'reason' => 'Restored after review.',
    ])->assertOk()
        ->assertJsonPath('data.state', Document::STATE_ACTIVE);

    $this->assertDatabaseHas('documents', [
        'id' => $document->id,
        'deleted_at' => null,
    ]);

    $deleteAudit = AuditLog::query()
        ->where('action', 'document_deleted')
        ->latest('id')
        ->firstOrFail();
    $restoreAudit = AuditLog::query()
        ->where('action', 'document_restored')
        ->latest('id')
        ->firstOrFail();

    expect($deleteAudit->context['reason'])->toBe('Duplicate record.')
        ->and($restoreAudit->context['reason'])->toBe('Restored after review.');
});

test('deleting and restoring a document require a reason', function () {
    seedAccessControl();

    $superAdmin = User::factory()->superAdmin()->create();
    $category = DocumentCategory::factory()->create();
    $document = ddmDocumentRecord($category, $superAdmin);

    Sanctum::actingAs($superAdmin);

    $this->deleteJson("/api/v1/ddm/documents/{$document->id}")
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['reason']]);

    $this->deleteJson("/api/v1/ddm/documents/{$document->id}", [
        'reason' => 'Duplicate record.',
    ])->assertNoContent();

    $this->postJson("/api/v1/ddm/documents/{$document->id}/restore")
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['reason']]);
});

test('an admin can edit document metadata and its access override', function () {
    seedAccessControl();

    $admin = User::factory()->admin()->create();
    $engineer = User::factory()->projectEngineer()->create();
    $category = DocumentCategory::factory()->create(['name' => 'Original Category']);
    $replacement = DocumentCategory::factory()->create(['name' => 'Replacement Category']);
    $inactive = DocumentCategory::factory()->inactive()->create(['name' => 'Inactive Category']);
    $document = ddmDocumentRecord($category, $admin, [
        'title' => 'Old Title',
        'access_level' => null,
    ]);

    Sanctum::actingAs($engineer);
    $this->patchJson("/api/v1/ddm/documents/{$document->id}", [
        'title' => 'Not Allowed',
    ])->assertForbidden();

    Sanctum::actingAs($admin);
    $this->patchJson("/api/v1/ddm/documents/{$document->id}", [
        'title' => 'New Title',
        'category_id' => $replacement->id,
        'access_level' => DocumentAccessLevel::Restricted->value,
        'remarks' => 'Metadata corrected.',
    ])->assertOk()
        ->assertJsonPath('data.title', 'New Title')
        ->assertJsonPath('data.category.id', $replacement->id)
        ->assertJsonPath('data.access_level', DocumentAccessLevel::Restricted->value)
        ->assertJsonPath('data.effective_access_level', DocumentAccessLevel::Restricted->value);

    $document->refresh();

    expect($document->title)->toBe('New Title')
        ->and($document->category_id)->toBe($replacement->id);

    $audit = AuditLog::query()
        ->where('action', 'document_updated')
        ->latest('id')
        ->firstOrFail();

    expect($audit->old_values['title'])->toBe('Old Title')
        ->and($audit->new_values['title'])->toBe('New Title');

    $this->patchJson("/api/v1/ddm/documents/{$document->id}", [
        'category_id' => $inactive->id,
    ])->assertStatus(422)
        ->assertJsonStructure(['errors' => ['category_id']]);
});

test('the DDM document list applies DDM scope, state, access, date, uploader, and text filters', function () {
    seedAccessControl();

    $admin = User::factory()->superAdmin()->create();
    $engineer = User::factory()->projectEngineer()->create();
    $openCategory = DocumentCategory::factory()->create([
        'name' => 'Open Records',
        'default_access_level' => DocumentAccessLevel::Open,
    ]);
    $restrictedCategory = DocumentCategory::factory()->create([
        'name' => 'Restricted Records',
        'default_access_level' => DocumentAccessLevel::Restricted,
    ]);

    $open = ddmDocumentRecord($openCategory, $engineer, [
        'title' => 'Budget Report',
        'reference_no' => 'BUD-2026',
        'document_date' => '2026-02-01',
    ]);
    $inheritedRestricted = ddmDocumentRecord($restrictedCategory, $engineer, [
        'title' => 'Personnel File',
        'reference_no' => 'HR-2026',
        'document_date' => '2026-03-01',
    ]);
    $archived = ddmDocumentRecord($openCategory, $engineer, [
        'title' => 'Old Budget',
        'is_archived' => true,
        'archived_at' => now(),
        'archived_by' => $admin->id,
    ]);
    $deleted = ddmDocumentRecord($openCategory, $engineer, ['title' => 'Deleted Budget']);
    $deleted->delete();

    $project = Project::factory()->create();
    $pmmsCategory = DocumentCategory::factory()->pmms()->create();
    $projectDocument = ddmDocumentRecord($pmmsCategory, $engineer, [
        'project_id' => $project->id,
        'title' => 'Project Plan',
    ]);

    Sanctum::actingAs($admin);

    $this->getJson('/api/v1/ddm/documents')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonMissing(['id' => $archived->id])
        ->assertJsonMissing(['id' => $deleted->id])
        ->assertJsonMissing(['id' => $projectDocument->id]);

    $this->getJson('/api/v1/ddm/documents?state=archived')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $archived->id);

    $this->getJson('/api/v1/ddm/documents?state=deleted')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $deleted->id);

    $this->getJson('/api/v1/ddm/documents?state=all')
        ->assertOk()
        ->assertJsonCount(3, 'data');

    $this->getJson('/api/v1/ddm/documents?q=Budget')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $open->id);

    $this->getJson('/api/v1/ddm/documents?access_level=restricted')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $inheritedRestricted->id);

    $this->getJson('/api/v1/ddm/documents?reference_no=HR-2026')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $inheritedRestricted->id);

    $this->getJson('/api/v1/ddm/documents?category_id='.$openCategory->id)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $open->id);

    $this->getJson('/api/v1/ddm/documents?state=all&category_id='.$openCategory->id)
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->getJson('/api/v1/ddm/documents?date_from=2026-02-15')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $inheritedRestricted->id);

    $this->getJson('/api/v1/ddm/documents?uploader='.$engineer->id)
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('document reads require DDM document view permission', function (RoleName $role, int $status) {
    seedAccessControl();

    $user = User::factory()->for(roleNamed($role))->create();
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/ddm/documents')->assertStatus($status);
})->with([
    'super admin' => [RoleName::SuperAdmin, 200],
    'admin' => [RoleName::Admin, 200],
    'project engineer' => [RoleName::ProjectEngineer, 200],
    'viewer' => [RoleName::Viewer, 403],
]);

test('a viewer granted DDM document view can read documents', function () {
    seedAccessControl();

    $superAdmin = User::factory()->superAdmin()->create();
    $viewer = User::factory()->viewer()->create();
    $permission = Permission::query()
        ->where('name', PermissionName::DdmDocumentsView->value)
        ->firstOrFail();
    $viewer->grantedPermissions()->attach($permission, ['granted_by' => $superAdmin->id]);

    Sanctum::actingAs($viewer);

    $this->getJson('/api/v1/ddm/documents')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('the can_download flag follows the effective access level and approved requests', function () {
    seedAccessControl();

    $admin = User::factory()->admin()->create();
    $superAdmin = User::factory()->superAdmin()->create();
    $engineer = User::factory()->projectEngineer()->create();
    $restrictedCategory = DocumentCategory::factory()->restricted()->create();
    $confidentialCategory = DocumentCategory::factory()->confidential()->create();
    $restricted = ddmDocumentRecord($restrictedCategory, $admin, ['title' => 'Restricted']);
    $confidential = ddmDocumentRecord($confidentialCategory, $admin, ['title' => 'Confidential']);

    Sanctum::actingAs($engineer);

    $this->getJson("/api/v1/ddm/documents/{$restricted->id}")
        ->assertOk()
        ->assertJsonPath('data.can_download', false);

    $this->getJson("/api/v1/ddm/documents/{$confidential->id}")
        ->assertOk()
        ->assertJsonPath('data.can_download', false);

    DocumentDownloadRequest::factory()->expired()->create([
        'document_id' => $restricted->id,
        'requested_by' => $engineer->id,
        'decided_by' => $admin->id,
    ]);

    $this->getJson("/api/v1/ddm/documents/{$restricted->id}")
        ->assertOk()
        ->assertJsonPath('data.can_download', false);

    DocumentDownloadRequest::factory()->approved()->create([
        'document_id' => $restricted->id,
        'requested_by' => $engineer->id,
        'decided_by' => $admin->id,
    ]);

    $this->getJson("/api/v1/ddm/documents/{$restricted->id}")
        ->assertOk()
        ->assertJsonPath('data.can_download', true);

    Sanctum::actingAs($admin);
    $this->getJson("/api/v1/ddm/documents/{$restricted->id}")
        ->assertOk()
        ->assertJsonPath('data.can_download', true);
    $this->getJson("/api/v1/ddm/documents/{$confidential->id}")
        ->assertOk()
        ->assertJsonPath('data.can_download', false);

    Sanctum::actingAs($superAdmin);
    $this->getJson("/api/v1/ddm/documents/{$restricted->id}")
        ->assertOk()
        ->assertJsonPath('data.can_download', true);
    $this->getJson("/api/v1/ddm/documents/{$confidential->id}")
        ->assertOk()
        ->assertJsonPath('data.can_download', true);
});

test('DDM document routes do not expose PMMS project documents', function () {
    seedAccessControl();

    $superAdmin = User::factory()->superAdmin()->create();
    $project = Project::factory()->create();
    $category = DocumentCategory::factory()->pmms()->create();
    $document = ddmDocumentRecord($category, $superAdmin, [
        'project_id' => $project->id,
        'title' => 'Project Supporting Document',
    ]);

    Sanctum::actingAs($superAdmin);

    $this->getJson("/api/v1/ddm/documents/{$document->id}")
        ->assertNotFound()
        ->assertJsonPath('code', 'not_found');

    $this->patchJson("/api/v1/ddm/documents/{$document->id}", [
        'title' => 'Changed',
    ])->assertNotFound()
        ->assertJsonPath('code', 'not_found');

    $this->postJson("/api/v1/ddm/documents/{$document->id}/archive")
        ->assertNotFound()
        ->assertJsonPath('code', 'not_found');
});
