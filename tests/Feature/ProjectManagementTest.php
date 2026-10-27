<?php

use App\Enums\ProjectStatus;
use App\Enums\RoleName;
use App\Models\Project;
use App\Models\ProjectCategory;
use App\Models\ProjectHistory;
use App\Models\ProjectUpdate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function pmmsProjectPayload(ProjectCategory $category, array $overrides = []): array
{
    return array_merge([
        'project_category_id' => $category->id,
        'implementation_year' => 2026,
        'project_name' => 'City Drainage Improvement',
        'contract_number' => 'CN-2026-001',
        'location' => 'Barangay San Isidro',
        'region' => 'Region III',
        'lgu' => 'City of San Fernando',
        'department' => 'City Engineering Office',
        'implementing_office' => 'City Engineering Office',
        'project_manager' => 'Engr. Display Name',
        'fund_source' => 'LGU',
        'contract_amount' => '1500000.00',
        'revised_amount' => null,
        'planned_start_date' => '2026-01-05',
        'planned_finish_date' => '2026-08-31',
        'actual_start_date' => null,
        'actual_finish_date' => null,
        'contractor' => 'Sample Builders Inc.',
        'remarks' => 'Initial registration.',
    ], $overrides);
}

test('a project engineer creates a project for himself and client ownership fields are ignored', function () {
    seedAccessControl();

    $engineer = User::factory()->projectEngineer()->create();
    $otherEngineer = User::factory()->projectEngineer()->create();
    $category = ProjectCategory::factory()->create();

    Sanctum::actingAs($engineer);

    $response = $this->postJson('/api/v1/pmms/projects', pmmsProjectPayload($category, [
        'assigned_pe_id' => $otherEngineer->id,
        'created_by' => $otherEngineer->id,
        'status' => ProjectStatus::Completed->value,
        'progress_percentage' => 100,
    ]));

    $response->assertCreated()
        ->assertJsonPath('data.assigned_pe_id', $engineer->id)
        ->assertJsonPath('data.created_by', $engineer->id)
        ->assertJsonPath('data.status', ProjectStatus::NotStarted->value)
        ->assertJsonPath('data.progress_percentage', 0)
        ->assertJsonPath('data.contract_amount', '1500000.00');

    $project = Project::query()->firstOrFail();

    expect($project->assigned_pe_id)->toBe($engineer->id)
        ->and($project->created_by)->toBe($engineer->id)
        ->and($project->status)->toBe(ProjectStatus::NotStarted);

    $this->assertDatabaseHas('project_history', [
        'project_id' => $project->id,
        'user_id' => $engineer->id,
        'action' => 'created',
    ]);

    $this->assertDatabaseHas('audit_logs', [
        'user_id' => $engineer->id,
        'module' => 'pmms',
        'action' => 'project_created',
        'auditable_type' => Project::class,
        'auditable_id' => $project->id,
    ]);
});

test('only project engineers can create projects', function (RoleName $role, int $status) {
    seedAccessControl();

    $user = User::factory()->for(roleNamed($role))->create();
    $category = ProjectCategory::factory()->create();

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/pmms/projects', pmmsProjectPayload($category))
        ->assertStatus($status);
})->with([
    'super admin' => [RoleName::SuperAdmin, 403],
    'admin' => [RoleName::Admin, 403],
    'project engineer' => [RoleName::ProjectEngineer, 201],
    'viewer' => [RoleName::Viewer, 403],
]);

test('project creation validates date order and category availability', function () {
    seedAccessControl();

    $engineer = User::factory()->projectEngineer()->create();
    $category = ProjectCategory::factory()->create();
    $inactiveCategory = ProjectCategory::factory()->inactive()->create();

    Sanctum::actingAs($engineer);

    $this->postJson('/api/v1/pmms/projects', pmmsProjectPayload($category, [
        'planned_start_date' => '2026-08-31',
        'planned_finish_date' => '2026-01-05',
    ]))->assertStatus(422)->assertJsonStructure(['errors' => ['planned_finish_date']]);

    $this->postJson('/api/v1/pmms/projects', pmmsProjectPayload($inactiveCategory))
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['project_category_id']]);
});

test('project categories are managed by admins and audited', function () {
    seedAccessControl();

    $admin = User::factory()->admin()->create();
    Sanctum::actingAs($admin);

    $categoryId = $this->postJson('/api/v1/pmms/categories', [
        'name' => 'Road Construction',
    ])->assertCreated()
        ->assertJsonPath('data.name', 'Road Construction')
        ->assertJsonPath('data.is_active', true)
        ->json('data.id');

    $this->patchJson("/api/v1/pmms/categories/{$categoryId}", [
        'name' => 'Road and Bridge Construction',
        'is_active' => false,
    ])->assertOk()
        ->assertJsonPath('data.name', 'Road and Bridge Construction')
        ->assertJsonPath('data.is_active', false);

    $this->assertDatabaseHas('audit_logs', [
        'module' => 'pmms',
        'action' => 'category_created',
        'auditable_id' => $categoryId,
    ]);

    $this->assertDatabaseHas('audit_logs', [
        'module' => 'pmms',
        'action' => 'category_updated',
        'auditable_id' => $categoryId,
    ]);

    $this->postJson('/api/v1/pmms/categories', ['name' => 'Road and Bridge Construction'])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['name']]);
});

test('only users with category permission manage project categories', function (RoleName $role, int $status) {
    seedAccessControl();

    $user = User::factory()->for(roleNamed($role))->create();
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/pmms/categories', ['name' => 'Category '.$role->value])
        ->assertStatus($status);
})->with([
    'super admin' => [RoleName::SuperAdmin, 201],
    'admin' => [RoleName::Admin, 201],
    'project engineer' => [RoleName::ProjectEngineer, 403],
    'viewer' => [RoleName::Viewer, 403],
]);

test('a project engineer can only list and view his own projects', function () {
    seedAccessControl();

    $engineer = User::factory()->projectEngineer()->create();
    $otherEngineer = User::factory()->projectEngineer()->create();

    $own = Project::factory()->ownedBy($engineer)->create(['project_name' => 'Own Project']);
    $other = Project::factory()->ownedBy($otherEngineer)->create(['project_name' => 'Other Project']);

    Sanctum::actingAs($engineer);

    $this->getJson('/api/v1/pmms/projects')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $own->id)
        ->assertJsonPath('data.0.project_name', 'Own Project');

    $this->getJson("/api/v1/pmms/projects/{$own->id}")->assertOk();
    $this->getJson("/api/v1/pmms/projects/{$other->id}")->assertNotFound();
    $this->getJson("/api/v1/pmms/projects/{$other->id}/history")->assertNotFound();
    $this->getJson("/api/v1/pmms/projects/{$other->id}/updates")->assertNotFound();
});

test('admins and super admins view all projects but cannot edit project data', function (RoleName $role) {
    seedAccessControl();

    $user = User::factory()->for(roleNamed($role))->create();
    $project = Project::factory()->create();

    Sanctum::actingAs($user);

    $this->getJson("/api/v1/pmms/projects/{$project->id}")->assertOk();
    $this->getJson('/api/v1/pmms/projects')->assertOk()->assertJsonCount(1, 'data');

    $this->patchJson("/api/v1/pmms/projects/{$project->id}", ['project_name' => 'Changed'])
        ->assertForbidden();
    $this->postJson("/api/v1/pmms/projects/{$project->id}/start")->assertForbidden();
    $this->postJson("/api/v1/pmms/projects/{$project->id}/progress", [
        'report_date' => now()->toDateString(),
        'progress_percentage' => 50,
    ])->assertForbidden();
})->with([
    'admin' => [RoleName::Admin],
    'super admin' => [RoleName::SuperAdmin],
]);

test('a project engineer updates his own project and planned dates lock after start', function () {
    seedAccessControl();

    $engineer = User::factory()->projectEngineer()->create();
    $project = Project::factory()->ownedBy($engineer)->create([
        'status' => ProjectStatus::NotStarted,
        'planned_start_date' => '2026-01-05',
        'planned_finish_date' => '2026-08-31',
    ]);

    Sanctum::actingAs($engineer);

    $this->patchJson("/api/v1/pmms/projects/{$project->id}", [
        'project_name' => 'Updated Project Name',
        'remarks' => 'Corrected the project title.',
    ])->assertOk()
        ->assertJsonPath('data.project_name', 'Updated Project Name');

    $this->postJson("/api/v1/pmms/projects/{$project->id}/start")->assertOk();

    $this->patchJson("/api/v1/pmms/projects/{$project->id}", [
        'planned_finish_date' => '2026-12-31',
    ])->assertStatus(422)
        ->assertJsonStructure(['errors' => ['planned_finish_date']]);

    $this->assertDatabaseHas('project_history', [
        'project_id' => $project->id,
        'action' => 'updated',
        'field_name' => 'project_name',
    ]);
});

test('starting a project sets ongoing status and records history and audit', function () {
    seedAccessControl();

    $engineer = User::factory()->projectEngineer()->create();
    $project = Project::factory()->ownedBy($engineer)->create([
        'status' => ProjectStatus::NotStarted,
    ]);

    Sanctum::actingAs($engineer);

    $this->postJson("/api/v1/pmms/projects/{$project->id}/start", [
        'remarks' => 'Work order received.',
    ])->assertOk()
        ->assertJsonPath('data.status', ProjectStatus::Ongoing->value)
        ->assertJsonPath('data.actual_start_date', now()->toDateString());

    expect(ProjectHistory::query()->where('project_id', $project->id)->where('action', 'started')->exists())->toBeTrue();
    $this->assertDatabaseHas('audit_logs', [
        'module' => 'pmms',
        'action' => 'project_started',
        'auditable_id' => $project->id,
    ]);
});

test('progress reports move a not started project to ongoing and preserve previous progress', function () {
    seedAccessControl();

    $engineer = User::factory()->projectEngineer()->create();
    $project = Project::factory()->ownedBy($engineer)->create([
        'status' => ProjectStatus::NotStarted,
        'progress_percentage' => 0,
    ]);

    Sanctum::actingAs($engineer);

    $this->postJson("/api/v1/pmms/projects/{$project->id}/progress", [
        'report_date' => now()->toDateString(),
        'progress_percentage' => 50,
        'accomplishment' => 'Completed excavation works.',
        'remarks' => 'On schedule.',
    ])->assertOk()
        ->assertJsonPath('data.status', ProjectStatus::Ongoing->value)
        ->assertJsonPath('data.progress_percentage', 50);

    $update = ProjectUpdate::query()->firstOrFail();

    expect($update->previous_progress)->toBe(0)
        ->and($update->progress_percentage)->toBe(50)
        ->and($update->reported_by)->toBe($engineer->id);

    $history = ProjectHistory::query()
        ->where('project_id', $project->id)
        ->where('field_name', 'progress_percentage')
        ->firstOrFail();

    expect($history->action)->toBe('progress_updated')
        ->and($history->previous_value)->toBe(0)
        ->and($history->new_value)->toBe(50);
});

test('lowering progress requires remarks and reporting the same progress is allowed', function () {
    seedAccessControl();

    $engineer = User::factory()->projectEngineer()->create();
    $project = Project::factory()->ownedBy($engineer)->create([
        'status' => ProjectStatus::Ongoing,
        'progress_percentage' => 60,
    ]);

    Sanctum::actingAs($engineer);

    $this->postJson("/api/v1/pmms/projects/{$project->id}/progress", [
        'report_date' => now()->toDateString(),
        'progress_percentage' => 40,
    ])->assertStatus(422)->assertJsonStructure(['errors' => ['remarks']]);

    $this->postJson("/api/v1/pmms/projects/{$project->id}/progress", [
        'report_date' => now()->toDateString(),
        'progress_percentage' => 40,
        'remarks' => 'Re-measured actual accomplishment.',
    ])->assertOk()->assertJsonPath('data.progress_percentage', 40);

    $this->postJson("/api/v1/pmms/projects/{$project->id}/progress", [
        'report_date' => now()->toDateString(),
        'progress_percentage' => 40,
        'remarks' => 'Delay explanation without progress change.',
    ])->assertOk()->assertJsonPath('data.progress_percentage', 40);

    expect(ProjectUpdate::query()->count())->toBe(2)
        ->and(ProjectHistory::query()->where('project_id', $project->id)->where('action', 'progress_reported')->exists())->toBeTrue();
});

test('reporting one hundred percent completes the project', function () {
    seedAccessControl();

    $engineer = User::factory()->projectEngineer()->create();
    $project = Project::factory()->ownedBy($engineer)->create([
        'status' => ProjectStatus::Ongoing,
        'progress_percentage' => 90,
    ]);

    Sanctum::actingAs($engineer);

    $this->postJson("/api/v1/pmms/projects/{$project->id}/progress", [
        'report_date' => now()->toDateString(),
        'progress_percentage' => 100,
        'remarks' => 'All works completed.',
    ])->assertOk()
        ->assertJsonPath('data.status', ProjectStatus::Completed->value)
        ->assertJsonPath('data.progress_percentage', 100)
        ->assertJsonPath('data.indicator', 'completed');

    $project->refresh();

    expect($project->completed_at)->not->toBeNull();
    $this->assertDatabaseHas('audit_logs', [
        'module' => 'pmms',
        'action' => 'project_completed',
        'auditable_id' => $project->id,
    ]);
});

test('pausing and resuming a project records both events and restores the revised finish date', function () {
    seedAccessControl();

    $engineer = User::factory()->projectEngineer()->create();
    $project = Project::factory()->ownedBy($engineer)->create([
        'status' => ProjectStatus::Ongoing,
        'planned_finish_date' => '2026-08-31',
    ]);

    Sanctum::actingAs($engineer);

    $this->postJson("/api/v1/pmms/projects/{$project->id}/pause", [
        'status' => ProjectStatus::Suspended->value,
        'reason' => 'Heavy rain and inaccessible site.',
    ])->assertOk()
        ->assertJsonPath('data.status', ProjectStatus::Suspended->value)
        ->assertJsonPath('data.indicator', 'paused');

    expect($project->fresh()->paused_since)->not->toBeNull();

    $this->postJson("/api/v1/pmms/projects/{$project->id}/progress", [
        'report_date' => now()->toDateString(),
        'progress_percentage' => 55,
    ])->assertStatus(409);

    $this->postJson("/api/v1/pmms/projects/{$project->id}/resume", [
        'revised_finish_date' => now()->addDays(20)->toDateString(),
        'remarks' => 'Site access restored.',
    ])->assertOk()
        ->assertJsonPath('data.status', ProjectStatus::Ongoing->value)
        ->assertJsonPath('data.paused_since', null)
        ->assertJsonPath('data.revised_finish_date', now()->addDays(20)->toDateString())
        ->assertJsonPath('data.planned_finish_date', '2026-08-31');

    $this->assertDatabaseHas('project_history', [
        'project_id' => $project->id,
        'action' => 'suspended',
        'field_name' => 'status',
    ]);
    $this->assertDatabaseHas('project_history', [
        'project_id' => $project->id,
        'action' => 'resumed',
        'field_name' => 'revised_finish_date',
    ]);
    $this->assertDatabaseHas('audit_logs', [
        'module' => 'pmms',
        'action' => 'project_suspended',
        'auditable_id' => $project->id,
    ]);
    $this->assertDatabaseHas('audit_logs', [
        'module' => 'pmms',
        'action' => 'project_resumed',
        'auditable_id' => $project->id,
    ]);
});

test('pause and resume enforce their state and date rules', function () {
    seedAccessControl();

    $engineer = User::factory()->projectEngineer()->create();
    $project = Project::factory()->ownedBy($engineer)->create([
        'status' => ProjectStatus::Ongoing,
    ]);

    Sanctum::actingAs($engineer);

    $this->postJson("/api/v1/pmms/projects/{$project->id}/pause", [
        'status' => ProjectStatus::Suspended->value,
        'reason' => 'short',
    ])->assertStatus(422)->assertJsonStructure(['errors' => ['reason']]);

    $this->postJson("/api/v1/pmms/projects/{$project->id}/resume", [
        'revised_finish_date' => now()->addDays(10)->toDateString(),
        'remarks' => 'Not paused.',
    ])->assertStatus(409);

    $this->postJson("/api/v1/pmms/projects/{$project->id}/pause", [
        'status' => ProjectStatus::Postponed->value,
        'reason' => 'Funding release is pending.',
    ])->assertOk();

    $this->postJson("/api/v1/pmms/projects/{$project->id}/resume", [
        'revised_finish_date' => now()->subDay()->toDateString(),
        'remarks' => 'Invalid past date.',
    ])->assertStatus(422)->assertJsonStructure(['errors' => ['revised_finish_date']]);
});

test('administer actions terminate reopen reassign delete and restore projects', function () {
    seedAccessControl();

    $admin = User::factory()->admin()->create();
    $engineer = User::factory()->projectEngineer()->create();
    $newEngineer = User::factory()->projectEngineer()->create();
    $inactiveEngineer = User::factory()->projectEngineer()->inactive()->create();
    $viewer = User::factory()->viewer()->create();

    $ongoing = Project::factory()->ownedBy($engineer)->create([
        'status' => ProjectStatus::Ongoing,
    ]);

    Sanctum::actingAs($admin);

    $this->postJson("/api/v1/pmms/projects/{$ongoing->id}/terminate", [
        'reason' => 'Contract was formally terminated.',
    ])->assertOk()->assertJsonPath('data.status', ProjectStatus::Terminated->value);

    $this->postJson("/api/v1/pmms/projects/{$ongoing->id}/reopen", [
        'reason' => 'Termination was reconsidered.',
        'progress_percentage' => 35,
    ])->assertOk()
        ->assertJsonPath('data.status', ProjectStatus::Ongoing->value)
        ->assertJsonPath('data.progress_percentage', 35);

    $this->postJson("/api/v1/pmms/projects/{$ongoing->id}/reassign", [
        'assigned_pe_id' => $newEngineer->id,
        'reason' => 'Engineer reassigned by the section head.',
    ])->assertOk()->assertJsonPath('data.assigned_pe_id', $newEngineer->id);

    $this->postJson("/api/v1/pmms/projects/{$ongoing->id}/reassign", [
        'assigned_pe_id' => $inactiveEngineer->id,
        'reason' => 'Invalid inactive engineer.',
    ])->assertStatus(422)->assertJsonStructure(['errors' => ['assigned_pe_id']]);

    $this->postJson("/api/v1/pmms/projects/{$ongoing->id}/reassign", [
        'assigned_pe_id' => $viewer->id,
        'reason' => 'Invalid non-engineer.',
    ])->assertStatus(422)->assertJsonStructure(['errors' => ['assigned_pe_id']]);

    $this->deleteJson("/api/v1/pmms/projects/{$ongoing->id}", [
        'reason' => 'Duplicate project registry entry.',
    ])->assertNoContent();

    $this->assertSoftDeleted('projects', ['id' => $ongoing->id]);
    $this->getJson("/api/v1/pmms/projects/{$ongoing->id}")->assertNotFound();

    $this->postJson("/api/v1/pmms/projects/{$ongoing->id}/restore", [
        'reason' => 'Duplicate entry was corrected.',
    ])->assertOk()->assertJsonPath('data.id', $ongoing->id);

    $this->assertNotSoftDeleted('projects', ['id' => $ongoing->id]);

    $this->assertDatabaseHas('project_history', [
        'project_id' => $ongoing->id,
        'action' => 'terminated',
    ]);
    $this->assertDatabaseHas('project_history', [
        'project_id' => $ongoing->id,
        'action' => 'reopened',
    ]);
    $this->assertDatabaseHas('project_history', [
        'project_id' => $ongoing->id,
        'action' => 'reassigned',
    ]);
    $this->assertDatabaseHas('project_history', [
        'project_id' => $ongoing->id,
        'action' => 'deleted',
    ]);
    $this->assertDatabaseHas('project_history', [
        'project_id' => $ongoing->id,
        'action' => 'restored',
    ]);
});

test('project engineers and viewers cannot perform administer actions', function (RoleName $role) {
    seedAccessControl();

    $user = User::factory()->for(roleNamed($role))->create();
    $project = Project::factory()->create();

    Sanctum::actingAs($user);

    $this->postJson("/api/v1/pmms/projects/{$project->id}/terminate", [
        'reason' => 'Unauthorized termination attempt.',
    ])->assertForbidden();

    $this->deleteJson("/api/v1/pmms/projects/{$project->id}", [
        'reason' => 'Unauthorized delete attempt.',
    ])->assertForbidden();
})->with([
    'project engineer' => [RoleName::ProjectEngineer],
    'viewer' => [RoleName::Viewer],
]);

test('a project engineer cannot update another engineers project', function () {
    seedAccessControl();

    $engineer = User::factory()->projectEngineer()->create();
    $otherEngineer = User::factory()->projectEngineer()->create();
    $project = Project::factory()->ownedBy($otherEngineer)->create();

    Sanctum::actingAs($engineer);

    $this->patchJson("/api/v1/pmms/projects/{$project->id}", [
        'project_name' => 'Unauthorized change',
    ])->assertNotFound();

    $this->postJson("/api/v1/pmms/projects/{$project->id}/start")->assertNotFound();
});

test('project history and progress update endpoints return recorded events', function () {
    seedAccessControl();

    $engineer = User::factory()->projectEngineer()->create();
    $project = Project::factory()->ownedBy($engineer)->create([
        'status' => ProjectStatus::Ongoing,
        'progress_percentage' => 20,
    ]);

    Sanctum::actingAs($engineer);

    $this->postJson("/api/v1/pmms/projects/{$project->id}/progress", [
        'report_date' => now()->toDateString(),
        'progress_percentage' => 50,
        'accomplishment' => 'Completed sub-base preparation.',
        'remarks' => 'Works are on schedule.',
    ])->assertOk();

    $this->getJson("/api/v1/pmms/projects/{$project->id}/history")
        ->assertOk()
        ->assertJsonPath('data.0.action', 'progress_updated')
        ->assertJsonPath('data.0.field_name', 'progress_percentage')
        ->assertJsonPath('data.0.previous_value', 20)
        ->assertJsonPath('data.0.new_value', 50)
        ->assertJsonPath('data.0.actor.id', $engineer->id);

    $this->getJson("/api/v1/pmms/projects/{$project->id}/updates")
        ->assertOk()
        ->assertJsonPath('data.0.previous_progress', 20)
        ->assertJsonPath('data.0.progress_percentage', 50)
        ->assertJsonPath('data.0.accomplishment', 'Completed sub-base preparation.')
        ->assertJsonPath('data.0.reporter.id', $engineer->id);
});

test('reporting zero percent on a not started project records the actual start date', function () {
    seedAccessControl();

    $engineer = User::factory()->projectEngineer()->create();
    $project = Project::factory()->ownedBy($engineer)->create([
        'status' => ProjectStatus::NotStarted,
        'progress_percentage' => 0,
    ]);

    Sanctum::actingAs($engineer);

    $this->postJson("/api/v1/pmms/projects/{$project->id}/progress", [
        'report_date' => now()->toDateString(),
        'progress_percentage' => 0,
        'remarks' => 'Site mobilization started.',
    ])->assertOk()
        ->assertJsonPath('data.status', ProjectStatus::Ongoing->value)
        ->assertJsonPath('data.progress_percentage', 0)
        ->assertJsonPath('data.actual_start_date', now()->toDateString());
});

test('administer actions require a reason', function () {
    seedAccessControl();

    $admin = User::factory()->admin()->create();
    $engineer = User::factory()->projectEngineer()->create();
    $project = Project::factory()->ownedBy($engineer)->create([
        'status' => ProjectStatus::Ongoing,
    ]);

    Sanctum::actingAs($admin);

    $this->postJson("/api/v1/pmms/projects/{$project->id}/terminate")
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['reason']]);

    $this->postJson("/api/v1/pmms/projects/{$project->id}/reopen", [
        'progress_percentage' => 50,
    ])->assertStatus(422)->assertJsonStructure(['errors' => ['reason']]);

    $this->postJson("/api/v1/pmms/projects/{$project->id}/reassign", [
        'assigned_pe_id' => User::factory()->projectEngineer()->create()->id,
    ])->assertStatus(422)->assertJsonStructure(['errors' => ['reason']]);

    $this->deleteJson("/api/v1/pmms/projects/{$project->id}")
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['reason']]);

    $this->deleteJson("/api/v1/pmms/projects/{$project->id}", [
        'reason' => 'Duplicate project record.',
    ])->assertNoContent();

    $this->postJson("/api/v1/pmms/projects/{$project->id}/restore")
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['reason']]);
});

test('super admins can perform administer actions', function () {
    seedAccessControl();

    $superAdmin = User::factory()->superAdmin()->create();
    $project = Project::factory()->create([
        'status' => ProjectStatus::Ongoing,
    ]);

    Sanctum::actingAs($superAdmin);

    $this->postJson("/api/v1/pmms/projects/{$project->id}/terminate", [
        'reason' => 'Terminated by the Data Management Section Head.',
    ])->assertOk()->assertJsonPath('data.status', ProjectStatus::Terminated->value);
});

test('reassigning a project moves ownership and access to the new engineer', function () {
    seedAccessControl();

    $admin = User::factory()->admin()->create();
    $oldEngineer = User::factory()->projectEngineer()->create();
    $newEngineer = User::factory()->projectEngineer()->create();
    $project = Project::factory()->ownedBy($oldEngineer)->create();

    Sanctum::actingAs($admin);

    $this->postJson("/api/v1/pmms/projects/{$project->id}/reassign", [
        'assigned_pe_id' => $newEngineer->id,
        'reason' => 'Workload rebalancing approved by the section head.',
    ])->assertOk()->assertJsonPath('data.assigned_pe_id', $newEngineer->id);

    Sanctum::actingAs($oldEngineer);
    $this->getJson("/api/v1/pmms/projects/{$project->id}")->assertNotFound();

    Sanctum::actingAs($newEngineer);
    $this->getJson("/api/v1/pmms/projects/{$project->id}")->assertOk();
    $this->patchJson("/api/v1/pmms/projects/{$project->id}", [
        'project_name' => 'Reassigned Project',
    ])->assertOk()->assertJsonPath('data.project_name', 'Reassigned Project');
});

test('project categories can be listed and viewed by project engineers', function () {
    seedAccessControl();

    $engineer = User::factory()->projectEngineer()->create();
    $category = ProjectCategory::factory()->create(['name' => 'Flood Control']);
    ProjectCategory::factory()->create(['name' => 'Building Construction']);
    Project::factory()->create(['project_category_id' => $category->id]);

    Sanctum::actingAs($engineer);

    $this->getJson('/api/v1/pmms/categories')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->getJson("/api/v1/pmms/categories/{$category->id}")
        ->assertOk()
        ->assertJsonPath('data.name', 'Flood Control')
        ->assertJsonPath('data.projects_count', 1);

    $viewer = User::factory()->viewer()->create();
    Sanctum::actingAs($viewer);

    $this->getJson('/api/v1/pmms/categories')->assertForbidden();
});
