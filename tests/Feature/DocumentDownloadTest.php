<?php

use App\Enums\DocumentAccessLevel;
use App\Enums\DownloadRequestStatus;
use App\Enums\PermissionName;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\DocumentDownloadRequest;
use App\Models\DocumentVersion;
use App\Models\Permission;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function ddmDownloadPdfContent(string $marker = 'default'): string
{
    return "%PDF-1.4\n% {$marker}\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [] /Count 0 >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF";
}

/**
 * @return array{0: Document, 1: DocumentVersion}
 */
function ddmDownloadDocument(
    DocumentCategory $category,
    User $creator,
    ?DocumentAccessLevel $accessLevel = null,
    ?string $content = null,
): array {
    $document = Document::factory()->create([
        'category_id' => $category->id,
        'created_by' => $creator->id,
        'access_level' => $accessLevel,
        'latest_version_number' => 1,
    ]);

    $version = ddmDownloadVersion($document, $creator, 1, $content);

    return [$document, $version];
}

function ddmDownloadVersion(
    Document $document,
    User $uploader,
    int $versionNumber = 1,
    ?string $content = null,
): DocumentVersion {
    $content ??= ddmDownloadPdfContent('v'.$versionNumber);
    $path = 'documents/'.fake()->unique()->uuid().'.pdf';

    $version = DocumentVersion::factory()->create([
        'document_id' => $document->id,
        'uploaded_by' => $uploader->id,
        'version_number' => $versionNumber,
        'original_name' => 'document-v'.$versionNumber.'.pdf',
        'storage_disk' => 'local',
        'storage_path' => $path,
        'mime_type' => 'application/pdf',
        'file_size' => strlen($content),
        'checksum_sha256' => hash('sha256', $content),
    ]);

    Storage::disk('local')->put($path, $content);

    return $version;
}

function ddmGrantPermission(User $user, PermissionName $permission, User $grantedBy): void
{
    $record = Permission::query()
        ->where('name', $permission->value)
        ->firstOrFail();

    $user->grantedPermissions()->attach($record, ['granted_by' => $grantedBy->id]);
}

test('a project engineer can request a restricted document and the request is audited', function () {
    seedAccessControl();
    Storage::fake('local');

    $engineer = User::factory()->projectEngineer()->create();
    $category = DocumentCategory::factory()->restricted()->create();
    [$document] = ddmDownloadDocument($category, $engineer);

    Sanctum::actingAs($engineer);

    $this->postJson("/api/v1/ddm/documents/{$document->id}/download-requests", [
        'reason' => 'Required for the annual document review.',
    ])
        ->assertCreated()
        ->assertJsonPath('data.status', DownloadRequestStatus::Pending->value)
        ->assertJsonPath('data.effective_status', 'pending')
        ->assertJsonPath('data.is_lapsed', false)
        ->assertJsonPath('data.is_expired', false)
        ->assertJsonPath('data.requested_by', $engineer->id)
        ->assertJsonPath('data.document.id', $document->id)
        ->assertJsonPath('data.document.effective_access_level', DocumentAccessLevel::Restricted->value);

    $downloadRequest = DocumentDownloadRequest::query()->firstOrFail();

    expect($downloadRequest->requested_by)->toBe($engineer->id)
        ->and($downloadRequest->status)->toBe(DownloadRequestStatus::Pending)
        ->and($downloadRequest->expires_at)->toBeNull();

    $this->assertDatabaseHas('audit_logs', [
        'user_id' => $engineer->id,
        'module' => 'ddm',
        'action' => 'download_requested',
        'auditable_type' => DocumentDownloadRequest::class,
        'auditable_id' => $downloadRequest->id,
    ]);
});

test('download requests are rejected when the document can be downloaded directly', function () {
    seedAccessControl();
    Storage::fake('local');

    $admin = User::factory()->admin()->create();
    $engineer = User::factory()->projectEngineer()->create();
    $superAdmin = User::factory()->superAdmin()->create();

    $restrictedCategory = DocumentCategory::factory()->restricted()->create();
    $openCategory = DocumentCategory::factory()->create();
    $confidentialCategory = DocumentCategory::factory()->confidential()->create();

    [$restricted] = ddmDownloadDocument($restrictedCategory, $admin);
    [$open] = ddmDownloadDocument($openCategory, $admin);
    [$confidential] = ddmDownloadDocument($confidentialCategory, $admin);

    Sanctum::actingAs($admin);
    $this->postJson("/api/v1/ddm/documents/{$restricted->id}/download-requests", [
        'reason' => 'An admin can download this directly.',
    ])
        ->assertConflict()
        ->assertJsonPath('code', 'state_conflict');

    Sanctum::actingAs($engineer);
    $this->postJson("/api/v1/ddm/documents/{$open->id}/download-requests", [
        'reason' => 'Open documents do not need a request.',
    ])
        ->assertConflict()
        ->assertJsonPath('code', 'state_conflict');

    Sanctum::actingAs($superAdmin);
    $this->postJson("/api/v1/ddm/documents/{$confidential->id}/download-requests", [
        'reason' => 'A super admin can download this directly.',
    ])
        ->assertConflict()
        ->assertJsonPath('code', 'state_conflict');
});

test('a pending request is unique and a lapsed request can be replaced', function () {
    seedAccessControl();
    Storage::fake('local');

    $engineer = User::factory()->projectEngineer()->create();
    $category = DocumentCategory::factory()->restricted()->create();
    [$document] = ddmDownloadDocument($category, $engineer);

    Sanctum::actingAs($engineer);

    $payload = ['reason' => 'Required for ongoing document review.'];

    $this->postJson("/api/v1/ddm/documents/{$document->id}/download-requests", $payload)
        ->assertCreated();

    $this->postJson("/api/v1/ddm/documents/{$document->id}/download-requests", $payload)
        ->assertConflict()
        ->assertJsonPath('code', 'state_conflict');

    $firstRequest = DocumentDownloadRequest::query()->firstOrFail();
    $firstRequest->forceFill(['created_at' => now()->subDays(15)])->save();

    $this->postJson("/api/v1/ddm/documents/{$document->id}/download-requests", $payload)
        ->assertCreated();

    expect(DocumentDownloadRequest::query()->count())->toBe(2);
});

test('download request creation requires the right permissions and valid input', function () {
    seedAccessControl();
    Storage::fake('local');

    $superAdmin = User::factory()->superAdmin()->create();
    $engineer = User::factory()->projectEngineer()->create();
    $category = DocumentCategory::factory()->restricted()->create();

    [$document] = ddmDownloadDocument($category, $engineer);

    $viewer = User::factory()->viewer()->create();
    ddmGrantPermission($viewer, PermissionName::DdmDocumentsView, $superAdmin);

    Sanctum::actingAs($viewer);
    $this->postJson("/api/v1/ddm/documents/{$document->id}/download-requests", [
        'reason' => 'The request permission is still missing.',
    ])->assertForbidden();

    ddmGrantPermission($viewer, PermissionName::DdmDownloadsRequest, $superAdmin);

    $this->postJson("/api/v1/ddm/documents/{$document->id}/download-requests", [
        'reason' => 'short',
    ])
        ->assertUnprocessable()
        ->assertJsonStructure(['errors' => ['reason']]);

    $this->postJson("/api/v1/ddm/documents/{$document->id}/download-requests", [
        'reason' => 'A valid reason for the document request.',
    ])->assertCreated();

    $requestOnlyUser = User::factory()->viewer()->create();
    ddmGrantPermission($requestOnlyUser, PermissionName::DdmDownloadsRequest, $superAdmin);
    Sanctum::actingAs($requestOnlyUser);

    $this->postJson("/api/v1/ddm/documents/{$document->id}/download-requests", [
        'reason' => 'This user cannot view DDM documents.',
    ])->assertForbidden();

    $project = Project::factory()->create();
    $projectDocument = Document::factory()->forProject($project)->create([
        'category_id' => $category->id,
        'created_by' => $engineer->id,
    ]);

    Sanctum::actingAs($engineer);
    $this->postJson("/api/v1/ddm/documents/{$projectDocument->id}/download-requests", [
        'reason' => 'Project documents are not part of DDM.',
    ])->assertNotFound();
});

test('an admin can approve a restricted request and the requester can download', function () {
    seedAccessControl();
    Storage::fake('local');

    $engineer = User::factory()->projectEngineer()->create();
    $admin = User::factory()->admin()->create();
    $category = DocumentCategory::factory()->restricted()->create();
    [$document, $version] = ddmDownloadDocument($category, $admin);

    Sanctum::actingAs($engineer);
    $this->postJson("/api/v1/ddm/documents/{$document->id}/download-requests", [
        'reason' => 'Required for the annual review.',
    ])->assertCreated();

    $downloadRequest = DocumentDownloadRequest::query()->firstOrFail();

    Sanctum::actingAs($admin);
    $this->postJson("/api/v1/ddm/download-requests/{$downloadRequest->id}/approve", [
        'decision_note' => 'Approved for review.',
    ])
        ->assertOk()
        ->assertJsonPath('data.status', DownloadRequestStatus::Approved->value)
        ->assertJsonPath('data.effective_status', 'approved')
        ->assertJsonPath('data.decided_by', $admin->id)
        ->assertJsonPath('data.decision_note', 'Approved for review.');

    $downloadRequest->refresh();

    expect($downloadRequest->status)->toBe(DownloadRequestStatus::Approved)
        ->and($downloadRequest->decided_by)->toBe($admin->id)
        ->and($downloadRequest->expires_at)->not->toBeNull()
        ->and($downloadRequest->expires_at->between(
            now()->addDays(7)->subMinute(),
            now()->addDays(7)->addMinute(),
        ))->toBeTrue();

    $this->assertDatabaseHas('audit_logs', [
        'user_id' => $admin->id,
        'module' => 'ddm',
        'action' => 'download_request_approved',
        'auditable_type' => DocumentDownloadRequest::class,
        'auditable_id' => $downloadRequest->id,
    ]);

    Sanctum::actingAs($engineer);

    $response = $this->get("/api/v1/ddm/documents/{$document->id}/download");

    $response->assertOk()
        ->assertHeader('content-type', 'application/pdf')
        ->assertHeader('x-content-type-options', 'nosniff')
        ->assertStreamedContent(ddmDownloadPdfContent('v1'));

    expect($response->headers->get('content-disposition'))
        ->toContain('attachment')
        ->toContain($version->original_name);

    $audit = AuditLog::query()
        ->where('action', 'document_downloaded')
        ->latest('id')
        ->firstOrFail();

    expect($audit->user_id)->toBe($engineer->id)
        ->and($audit->auditable_id)->toBe($document->id)
        ->and($audit->context['version_id'])->toBe($version->id)
        ->and($audit->context['version_number'])->toBe(1);

    $this->travel(8)->days();

    $this->getJson("/api/v1/ddm/documents/{$document->id}/download")
        ->assertForbidden()
        ->assertJsonPath('code', 'download_approval_required');

    expect($downloadRequest->fresh()->isExpired())->toBeTrue();
});

test('approval decisions follow the document access level and block self approval', function () {
    seedAccessControl();
    Storage::fake('local');

    $admin = User::factory()->admin()->create();
    $superAdmin = User::factory()->superAdmin()->create();
    $engineer = User::factory()->projectEngineer()->create();

    $confidentialCategory = DocumentCategory::factory()->confidential()->create();
    [$confidential] = ddmDownloadDocument($confidentialCategory, $admin);

    $confidentialRequest = DocumentDownloadRequest::factory()->create([
        'document_id' => $confidential->id,
        'requested_by' => $engineer->id,
        'status' => DownloadRequestStatus::Pending,
    ]);

    Sanctum::actingAs($admin);
    $this->postJson("/api/v1/ddm/download-requests/{$confidentialRequest->id}/approve")
        ->assertForbidden()
        ->assertJsonPath('code', 'forbidden');

    expect($confidentialRequest->fresh()->status)->toBe(DownloadRequestStatus::Pending);

    $this->assertDatabaseHas('audit_logs', [
        'user_id' => $admin->id,
        'module' => 'ddm',
        'action' => 'download_request_decision_denied',
        'auditable_type' => DocumentDownloadRequest::class,
        'auditable_id' => $confidentialRequest->id,
    ]);

    Sanctum::actingAs($superAdmin);
    $this->postJson("/api/v1/ddm/download-requests/{$confidentialRequest->id}/approve")
        ->assertOk()
        ->assertJsonPath('data.status', DownloadRequestStatus::Approved->value);

    $restrictedCategory = DocumentCategory::factory()->restricted()->create();
    [$restricted] = ddmDownloadDocument($restrictedCategory, $admin);

    $selfRequest = DocumentDownloadRequest::factory()->create([
        'document_id' => $restricted->id,
        'requested_by' => $admin->id,
        'status' => DownloadRequestStatus::Pending,
    ]);

    Sanctum::actingAs($admin);
    $this->postJson("/api/v1/ddm/download-requests/{$selfRequest->id}/approve")
        ->assertForbidden()
        ->assertJsonPath('code', 'forbidden');

    expect($selfRequest->fresh()->status)->toBe(DownloadRequestStatus::Pending);
});

test('an admin can deny a restricted request and invalid decisions are rejected', function () {
    seedAccessControl();
    Storage::fake('local');

    $engineer = User::factory()->projectEngineer()->create();
    $admin = User::factory()->admin()->create();
    $category = DocumentCategory::factory()->restricted()->create();
    [$document] = ddmDownloadDocument($category, $admin);

    $downloadRequest = DocumentDownloadRequest::factory()->create([
        'document_id' => $document->id,
        'requested_by' => $engineer->id,
        'status' => DownloadRequestStatus::Pending,
    ]);

    Sanctum::actingAs($admin);
    $this->postJson("/api/v1/ddm/download-requests/{$downloadRequest->id}/deny", [
        'decision_note' => 'The request does not state a valid purpose.',
    ])
        ->assertOk()
        ->assertJsonPath('data.status', DownloadRequestStatus::Denied->value)
        ->assertJsonPath('data.effective_status', 'denied')
        ->assertJsonPath('data.decision_note', 'The request does not state a valid purpose.')
        ->assertJsonPath('data.expires_at', null);

    $this->assertDatabaseHas('audit_logs', [
        'user_id' => $admin->id,
        'module' => 'ddm',
        'action' => 'download_request_denied',
        'auditable_type' => DocumentDownloadRequest::class,
        'auditable_id' => $downloadRequest->id,
    ]);

    $this->postJson("/api/v1/ddm/download-requests/{$downloadRequest->id}/approve")
        ->assertConflict()
        ->assertJsonPath('code', 'state_conflict');

    $lapsedRequest = DocumentDownloadRequest::factory()->create([
        'document_id' => $document->id,
        'requested_by' => $engineer->id,
        'status' => DownloadRequestStatus::Pending,
    ]);
    $lapsedRequest->forceFill(['created_at' => now()->subDays(15)])->save();

    $this->postJson("/api/v1/ddm/download-requests/{$lapsedRequest->id}/approve")
        ->assertConflict()
        ->assertJsonPath('code', 'state_conflict');

    expect($lapsedRequest->fresh()->status)->toBe(DownloadRequestStatus::Pending);
});

test('approval duration is limited to the configured maximum', function () {
    seedAccessControl();
    Storage::fake('local');

    $engineer = User::factory()->projectEngineer()->create();
    $admin = User::factory()->admin()->create();
    $category = DocumentCategory::factory()->restricted()->create();
    [$document] = ddmDownloadDocument($category, $admin);

    $downloadRequest = DocumentDownloadRequest::factory()->create([
        'document_id' => $document->id,
        'requested_by' => $engineer->id,
        'status' => DownloadRequestStatus::Pending,
    ]);

    Sanctum::actingAs($admin);

    $this->postJson("/api/v1/ddm/download-requests/{$downloadRequest->id}/approve", [
        'approval_days' => 31,
    ])
        ->assertUnprocessable()
        ->assertJsonStructure(['errors' => ['approval_days']]);

    $this->postJson("/api/v1/ddm/download-requests/{$downloadRequest->id}/approve", [
        'approval_days' => 10,
    ])->assertOk();

    $downloadRequest->refresh();

    expect($downloadRequest->expires_at->between(
        now()->addDays(10)->subMinute(),
        now()->addDays(10)->addMinute(),
    ))->toBeTrue();
});

test('download request lists are scoped by requester and approval capability', function () {
    seedAccessControl();
    Storage::fake('local');

    $superAdmin = User::factory()->superAdmin()->create();
    $admin = User::factory()->admin()->create();
    $engineerOne = User::factory()->projectEngineer()->create();
    $engineerTwo = User::factory()->projectEngineer()->create();

    $restrictedCategory = DocumentCategory::factory()->restricted()->create();
    $confidentialCategory = DocumentCategory::factory()->confidential()->create();

    [$restrictedOne] = ddmDownloadDocument($restrictedCategory, $admin);
    [$restrictedTwo] = ddmDownloadDocument($restrictedCategory, $admin);
    [$confidential] = ddmDownloadDocument($confidentialCategory, $admin);

    $restrictedRequestOne = DocumentDownloadRequest::factory()->create([
        'document_id' => $restrictedOne->id,
        'requested_by' => $engineerOne->id,
        'status' => DownloadRequestStatus::Pending,
    ]);
    $restrictedRequestTwo = DocumentDownloadRequest::factory()->create([
        'document_id' => $restrictedTwo->id,
        'requested_by' => $engineerTwo->id,
        'status' => DownloadRequestStatus::Pending,
    ]);
    $confidentialRequest = DocumentDownloadRequest::factory()->create([
        'document_id' => $confidential->id,
        'requested_by' => $engineerTwo->id,
        'status' => DownloadRequestStatus::Pending,
    ]);
    $adminOwnConfidentialRequest = DocumentDownloadRequest::factory()->create([
        'document_id' => $confidential->id,
        'requested_by' => $admin->id,
        'status' => DownloadRequestStatus::Pending,
    ]);

    Sanctum::actingAs($engineerOne);
    $this->getJson('/api/v1/ddm/download-requests')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $restrictedRequestOne->id);

    Sanctum::actingAs($admin);
    $response = $this->getJson('/api/v1/ddm/download-requests')
        ->assertOk()
        ->assertJsonCount(3, 'data');

    expect(collect($response->json('data'))->pluck('id')->all())
        ->not->toContain($confidentialRequest->id);

    $this->getJson('/api/v1/ddm/download-requests?status=pending')
        ->assertOk()
        ->assertJsonCount(3, 'data');

    $this->getJson('/api/v1/ddm/download-requests?document_id='.$restrictedOne->id)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $restrictedRequestOne->id);

    $this->getJson('/api/v1/ddm/download-requests?requester='.$engineerTwo->id)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $restrictedRequestTwo->id);

    Sanctum::actingAs($superAdmin);
    $this->getJson('/api/v1/ddm/download-requests')
        ->assertOk()
        ->assertJsonCount(4, 'data')
        ->assertJsonFragment(['id' => $adminOwnConfidentialRequest->id]);
});

test('request details are hidden outside the viewer scope and project requests are excluded', function () {
    seedAccessControl();
    Storage::fake('local');

    $superAdmin = User::factory()->superAdmin()->create();
    $admin = User::factory()->admin()->create();
    $engineerOne = User::factory()->projectEngineer()->create();
    $engineerTwo = User::factory()->projectEngineer()->create();

    $restrictedCategory = DocumentCategory::factory()->restricted()->create();
    $confidentialCategory = DocumentCategory::factory()->confidential()->create();

    [$restricted] = ddmDownloadDocument($restrictedCategory, $admin);
    [$confidential] = ddmDownloadDocument($confidentialCategory, $admin);

    $restrictedRequest = DocumentDownloadRequest::factory()->create([
        'document_id' => $restricted->id,
        'requested_by' => $engineerOne->id,
        'status' => DownloadRequestStatus::Pending,
    ]);
    $confidentialRequest = DocumentDownloadRequest::factory()->create([
        'document_id' => $confidential->id,
        'requested_by' => $engineerTwo->id,
        'status' => DownloadRequestStatus::Pending,
    ]);

    $project = Project::factory()->create();
    $projectDocument = Document::factory()->forProject($project)->create([
        'category_id' => $restrictedCategory->id,
        'created_by' => $admin->id,
    ]);
    $projectRequest = DocumentDownloadRequest::factory()->create([
        'document_id' => $projectDocument->id,
        'requested_by' => $engineerOne->id,
        'status' => DownloadRequestStatus::Pending,
    ]);

    Sanctum::actingAs($engineerOne);
    $this->getJson("/api/v1/ddm/download-requests/{$restrictedRequest->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $restrictedRequest->id);
    $this->getJson("/api/v1/ddm/download-requests/{$confidentialRequest->id}")
        ->assertNotFound();
    $this->getJson("/api/v1/ddm/download-requests/{$projectRequest->id}")
        ->assertNotFound();

    Sanctum::actingAs($admin);
    $this->getJson("/api/v1/ddm/download-requests/{$restrictedRequest->id}")
        ->assertOk();
    $this->getJson("/api/v1/ddm/download-requests/{$confidentialRequest->id}")
        ->assertNotFound();

    Sanctum::actingAs($superAdmin);
    $this->getJson("/api/v1/ddm/download-requests/{$confidentialRequest->id}")
        ->assertOk();
    $this->getJson("/api/v1/ddm/download-requests/{$projectRequest->id}")
        ->assertNotFound();
});

test('downloads stream the current version with safe headers and audit the action', function () {
    seedAccessControl();
    Storage::fake('local');

    $admin = User::factory()->admin()->create();
    $engineer = User::factory()->projectEngineer()->create();
    $category = DocumentCategory::factory()->create();

    [$document] = ddmDownloadDocument($category, $admin);
    $secondVersion = ddmDownloadVersion($document, $admin, 2, ddmDownloadPdfContent('second'));
    $document->forceFill(['latest_version_number' => 2])->save();

    Sanctum::actingAs($engineer);

    $response = $this->get("/api/v1/ddm/documents/{$document->id}/download");

    $response->assertOk()
        ->assertHeader('content-type', 'application/pdf')
        ->assertHeader('x-content-type-options', 'nosniff')
        ->assertStreamedContent(ddmDownloadPdfContent('second'));

    expect($response->headers->get('content-disposition'))
        ->toContain('attachment')
        ->toContain($secondVersion->original_name);

    $audit = AuditLog::query()
        ->where('action', 'document_downloaded')
        ->latest('id')
        ->firstOrFail();

    expect($audit->user_id)->toBe($engineer->id)
        ->and($audit->auditable_id)->toBe($document->id)
        ->and($audit->context['version_id'])->toBe($secondVersion->id)
        ->and($audit->context['version_number'])->toBe(2)
        ->and(json_encode($audit->context))->not->toContain($secondVersion->storage_path);
});

test('restricted and confidential downloads require permission or an approved request', function () {
    seedAccessControl();
    Storage::fake('local');

    $engineer = User::factory()->projectEngineer()->create();
    $admin = User::factory()->admin()->create();
    $superAdmin = User::factory()->superAdmin()->create();

    $restrictedCategory = DocumentCategory::factory()->restricted()->create();
    $confidentialCategory = DocumentCategory::factory()->confidential()->create();

    [$restricted] = ddmDownloadDocument($restrictedCategory, $admin);
    [$confidential] = ddmDownloadDocument($confidentialCategory, $admin);

    Sanctum::actingAs($engineer);

    $this->getJson("/api/v1/ddm/documents/{$restricted->id}/download")
        ->assertForbidden()
        ->assertJsonPath('code', 'download_approval_required');

    $this->assertDatabaseHas('audit_logs', [
        'user_id' => $engineer->id,
        'module' => 'ddm',
        'action' => 'document_download_denied',
        'auditable_type' => Document::class,
        'auditable_id' => $restricted->id,
    ]);

    $this->assertDatabaseMissing('audit_logs', [
        'user_id' => $engineer->id,
        'action' => 'document_downloaded',
        'auditable_id' => $restricted->id,
    ]);

    DocumentDownloadRequest::factory()->approved()->create([
        'document_id' => $restricted->id,
        'requested_by' => $engineer->id,
        'decided_by' => $admin->id,
    ]);

    $this->get("/api/v1/ddm/documents/{$restricted->id}/download")
        ->assertOk()
        ->assertStreamedContent(ddmDownloadPdfContent('v1'));

    Sanctum::actingAs($admin);
    $this->get("/api/v1/ddm/documents/{$restricted->id}/download")
        ->assertOk()
        ->assertStreamedContent(ddmDownloadPdfContent('v1'));

    $this->getJson("/api/v1/ddm/documents/{$confidential->id}/download")
        ->assertForbidden()
        ->assertJsonPath('code', 'download_approval_required');

    Sanctum::actingAs($superAdmin);
    $this->get("/api/v1/ddm/documents/{$confidential->id}/download")
        ->assertOk()
        ->assertStreamedContent(ddmDownloadPdfContent('v1'));
});

test('a viewer with DDM view can download open documents but not restricted ones', function () {
    seedAccessControl();
    Storage::fake('local');

    $superAdmin = User::factory()->superAdmin()->create();
    $viewer = User::factory()->viewer()->create();
    ddmGrantPermission($viewer, PermissionName::DdmDocumentsView, $superAdmin);

    $openCategory = DocumentCategory::factory()->create();
    $restrictedCategory = DocumentCategory::factory()->restricted()->create();
    [$open] = ddmDownloadDocument($openCategory, $superAdmin);
    [$restricted] = ddmDownloadDocument($restrictedCategory, $superAdmin);

    Sanctum::actingAs($viewer);

    $this->get("/api/v1/ddm/documents/{$open->id}/download")
        ->assertOk()
        ->assertStreamedContent(ddmDownloadPdfContent('v1'));

    $this->getJson("/api/v1/ddm/documents/{$restricted->id}/download")
        ->assertForbidden()
        ->assertJsonPath('code', 'download_approval_required');
});

test('archived documents remain downloadable', function () {
    seedAccessControl();
    Storage::fake('local');

    $admin = User::factory()->admin()->create();
    $engineer = User::factory()->projectEngineer()->create();
    $category = DocumentCategory::factory()->create();
    [$document] = ddmDownloadDocument($category, $admin, null, ddmDownloadPdfContent('archived'));

    $document->forceFill([
        'is_archived' => true,
        'archived_at' => now(),
        'archived_by' => $admin->id,
    ])->save();

    Sanctum::actingAs($engineer);

    $this->get("/api/v1/ddm/documents/{$document->id}/download")
        ->assertOk()
        ->assertStreamedContent(ddmDownloadPdfContent('archived'));
});

test('downloads reject project and deleted documents and report missing files', function () {
    seedAccessControl();
    Storage::fake('local');

    $admin = User::factory()->admin()->create();
    $engineer = User::factory()->projectEngineer()->create();
    $category = DocumentCategory::factory()->create();

    $project = Project::factory()->create();
    $projectDocument = Document::factory()->forProject($project)->create([
        'category_id' => $category->id,
        'created_by' => $admin->id,
    ]);

    [$deleted] = ddmDownloadDocument($category, $admin);
    $deleted->delete();

    [$missing] = ddmDownloadDocument($category, $admin);
    $missingVersion = $missing->versions()->firstOrFail();
    Storage::disk('local')->delete($missingVersion->storage_path);

    Sanctum::actingAs($engineer);

    $this->getJson("/api/v1/ddm/documents/{$projectDocument->id}/download")
        ->assertNotFound();
    $this->getJson("/api/v1/ddm/documents/{$deleted->id}/download")
        ->assertNotFound();
    $this->getJson("/api/v1/ddm/documents/{$missing->id}/download")
        ->assertStatus(500)
        ->assertJsonPath('code', 'file_missing');
});
