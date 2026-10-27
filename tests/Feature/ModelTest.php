<?php

use App\Enums\DocumentAccessLevel;
use App\Enums\DocumentCategoryScope;
use App\Enums\DownloadRequestStatus;
use App\Enums\ProjectStatus;
use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\DocumentDownloadRequest;
use App\Models\DocumentVersion;
use App\Models\Permission;
use App\Models\Project;
use App\Models\ProjectCategory;
use App\Models\ProjectHistory;
use App\Models\ProjectUpdate;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;

uses(RefreshDatabase::class);

test('factories create every model with its relationships', function () {
    $role = Role::factory()->named(RoleName::ProjectEngineer)->create();
    $permission = Permission::factory()->create();
    $role->permissions()->attach($permission);

    $user = User::factory()->for($role)->create();
    $user->grantedPermissions()->attach($permission, ['granted_by' => $user->id]);

    expect($user->role->is($role))->toBeTrue()
        ->and($user->grantedPermissions->first()->is($permission))->toBeTrue();

    $category = ProjectCategory::factory()->create();
    $project = Project::factory()->create([
        'project_category_id' => $category->id,
        'assigned_pe_id' => $user->id,
        'created_by' => $user->id,
    ]);

    expect($project->category->is($category))->toBeTrue()
        ->and($project->assignedPe->is($user))->toBeTrue()
        ->and($project->creator->is($user))->toBeTrue();

    $update = ProjectUpdate::factory()->for($project)->create(['reported_by' => $user->id]);
    $history = ProjectHistory::factory()->for($project)->create(['user_id' => $user->id]);

    expect($update->project->is($project))->toBeTrue()
        ->and($update->reporter->is($user))->toBeTrue()
        ->and($history->project->is($project))->toBeTrue()
        ->and($history->user->is($user))->toBeTrue();

    $documentCategory = DocumentCategory::factory()->create();
    $document = Document::factory()->create([
        'category_id' => $documentCategory->id,
        'created_by' => $user->id,
    ]);
    $version = DocumentVersion::factory()->for($document)->create(['uploaded_by' => $user->id]);
    $downloadRequest = DocumentDownloadRequest::factory()->for($document)->create([
        'requested_by' => $user->id,
    ]);

    expect($document->category->is($documentCategory))->toBeTrue()
        ->and($document->creator->is($user))->toBeTrue()
        ->and($document->versions->first()->is($version))->toBeTrue()
        ->and($document->latestVersion->is($version))->toBeTrue()
        ->and($version->uploader->is($user))->toBeTrue()
        ->and($downloadRequest->document->is($document))->toBeTrue()
        ->and($downloadRequest->requester->is($user))->toBeTrue();

    $auditLog = AuditLog::factory()->create(['user_id' => $user->id]);

    expect($auditLog->user->is($user))->toBeTrue();
});

test('model casts expose the confirmed domain types', function () {
    $project = Project::factory()->create([
        'contract_amount' => '1500000.00',
        'revised_amount' => '1750000.50',
        'planned_start_date' => '2026-01-05',
        'planned_finish_date' => '2026-12-20',
        'revised_finish_date' => '2027-03-15',
    ])->refresh();

    expect($project->status)->toBe(ProjectStatus::NotStarted)
        ->and($project->contract_amount)->toBe('1500000.00')
        ->and($project->revised_amount)->toBe('1750000.50')
        ->and($project->planned_start_date->toDateString())->toBe('2026-01-05')
        ->and($project->effective_finish_date->toDateString())->toBe('2027-03-15')
        ->and($project->progress_percentage)->toBe(0);

    $category = DocumentCategory::factory()->pmms()->confidential()->create();

    expect($category->scope)->toBe(DocumentCategoryScope::Pmms)
        ->and($category->default_access_level)->toBe(DocumentAccessLevel::Confidential);

    $document = Document::factory()->create([
        'category_id' => $category->id,
        'access_level' => DocumentAccessLevel::Restricted,
    ]);

    expect($document->access_level)->toBe(DocumentAccessLevel::Restricted)
        ->and($document->is_archived)->toBeFalse();

    $downloadRequest = DocumentDownloadRequest::factory()->create();

    expect($downloadRequest->status)->toBe(DownloadRequestStatus::Pending);
});

test('project status helpers separate open and paused states', function () {
    expect(ProjectStatus::NotStarted->isOpen())->toBeTrue()
        ->and(ProjectStatus::Ongoing->isOpen())->toBeTrue()
        ->and(ProjectStatus::Suspended->isPaused())->toBeTrue()
        ->and(ProjectStatus::Postponed->isPaused())->toBeTrue()
        ->and(ProjectStatus::Completed->isOpen())->toBeFalse()
        ->and(ProjectStatus::Terminated->isPaused())->toBeFalse();
});

test('document and project scopes constrain records to their module or owner', function () {
    $project = Project::factory()->create();
    $ownDocument = Document::factory()->create();
    $projectDocument = Document::factory()->forProject($project)->create();

    expect(Document::withoutProject()->pluck('id')->all())->toBe([$ownDocument->id])
        ->and(Document::forProject($project)->pluck('id')->all())->toBe([$projectDocument->id])
        ->and(Project::ownedBy($project->assigned_pe_id)->pluck('id')->all())->toBe([$project->id]);
});

test('privileged and lifecycle fields are excluded from mass assignment', function () {
    $project = new Project([
        'project_name' => 'Road Improvement',
        'assigned_pe_id' => 999,
        'created_by' => 999,
        'status' => ProjectStatus::Completed->value,
        'progress_percentage' => 100,
        'revised_finish_date' => '2030-01-01',
    ]);

    expect($project->getAttributes())
        ->toHaveKey('project_name')
        ->not->toHaveKeys([
            'assigned_pe_id',
            'created_by',
            'status',
            'progress_percentage',
            'revised_finish_date',
            'effective_finish_date',
        ]);

    $document = new Document([
        'title' => 'Memorandum',
        'project_id' => 1,
        'created_by' => 999,
        'latest_version_number' => 9,
        'is_archived' => true,
    ]);

    expect($document->getAttributes())
        ->toHaveKey('title')
        ->not->toHaveKeys([
            'project_id',
            'created_by',
            'latest_version_number',
            'is_archived',
            'archived_by',
            'archived_at',
        ]);

    $user = new User([
        'full_name' => 'Juan dela Cruz',
        'role_id' => 999,
        'is_active' => false,
    ]);

    expect($user->getAttributes())
        ->toHaveKey('full_name')
        ->not->toHaveKeys(['role_id', 'is_active', 'last_login_at']);
});

test('database seeder creates the four roles and an active super admin', function () {
    $this->seed();

    expect(Role::orderBy('name')->pluck('name')->all())->toBe([
        RoleName::Admin->value,
        RoleName::ProjectEngineer->value,
        RoleName::SuperAdmin->value,
        RoleName::Viewer->value,
    ]);

    $superAdmin = User::query()->where('email', 'test@example.com')->firstOrFail();

    expect($superAdmin->role->name)->toBe(RoleName::SuperAdmin->value)
        ->and($superAdmin->is_active)->toBeTrue();
});

test('hidden attributes are excluded from serialization', function () {
    $user = User::factory()->create();
    $version = DocumentVersion::factory()->create();

    expect($user->toArray())->not->toHaveKeys(['password', 'remember_token'])
        ->and($version->toArray())->not->toHaveKey('storage_path');
});

test('append-only models cannot be updated or deleted', function (string $model, string $attribute) {
    $record = $model::factory()->create();

    expect($record->getUpdatedAtColumn())->toBeNull()
        ->and(fn () => $record->update([$attribute => 'changed']))->toThrow(LogicException::class)
        ->and(fn () => $record->delete())->toThrow(LogicException::class);
})->with([
    'audit logs' => [AuditLog::class, 'action'],
    'project history' => [ProjectHistory::class, 'action'],
    'project updates' => [ProjectUpdate::class, 'remarks'],
    'document versions' => [DocumentVersion::class, 'change_note'],
]);
