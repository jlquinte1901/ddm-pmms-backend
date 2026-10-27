<?php

use App\Enums\PermissionName;
use App\Enums\ProjectStatus;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Project;
use App\Models\ProjectCategory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function pmmsToday(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-06-01', (string) config('app.timezone', 'Asia/Manila'))->startOfDay();
}

function pmmsFinishDate(int $daysFromToday): string
{
    return pmmsToday()->addDays($daysFromToday)->toDateString();
}

test('the 35 day indicator is calculated at read time from the effective finish date', function (int $daysRemaining, string $indicator) {
    $this->travelTo(pmmsToday()->setTime(9, 0));
    seedAccessControl();

    $engineer = User::factory()->projectEngineer()->create();
    $project = Project::factory()->ownedBy($engineer)->create([
        'status' => ProjectStatus::NotStarted,
        'planned_start_date' => '2026-01-05',
        'planned_finish_date' => pmmsFinishDate($daysRemaining),
    ]);

    Sanctum::actingAs($engineer);

    $this->getJson("/api/v1/pmms/projects/{$project->id}")
        ->assertOk()
        ->assertJsonPath('data.indicator', $indicator)
        ->assertJsonPath('data.days_remaining', $daysRemaining);

    expect($project->fresh()->status)->toBe(ProjectStatus::NotStarted);
})->with([
    '36 days is normal' => [36, 'normal'],
    '35 days is critical' => [35, 'critical'],
    '0 days is critical' => [0, 'critical'],
    '-1 day is overdue' => [-1, 'overdue'],
]);

test('completed terminated and paused projects receive their own indicator states', function () {
    $this->travelTo(pmmsToday()->setTime(9, 0));
    seedAccessControl();

    $engineer = User::factory()->projectEngineer()->create();

    $completed = Project::factory()->ownedBy($engineer)->completed()->create([
        'planned_finish_date' => '2026-05-01',
    ]);
    $terminated = Project::factory()->ownedBy($engineer)->terminated()->create([
        'planned_finish_date' => '2026-05-01',
    ]);
    $paused = Project::factory()->ownedBy($engineer)->paused()->create([
        'planned_finish_date' => '2026-05-01',
        'paused_since' => '2026-05-30',
    ]);

    Sanctum::actingAs($engineer);

    $this->getJson("/api/v1/pmms/projects/{$completed->id}")
        ->assertOk()
        ->assertJsonPath('data.indicator', 'completed')
        ->assertJsonPath('data.status', ProjectStatus::Completed->value);

    $this->getJson("/api/v1/pmms/projects/{$terminated->id}")
        ->assertOk()
        ->assertJsonPath('data.indicator', 'terminated')
        ->assertJsonPath('data.status', ProjectStatus::Terminated->value);

    $this->getJson("/api/v1/pmms/projects/{$paused->id}")
        ->assertOk()
        ->assertJsonPath('data.indicator', 'paused')
        ->assertJsonPath('data.status', ProjectStatus::Suspended->value)
        ->assertJsonPath('data.days_paused', 2);
});

test('a revised finish date replaces the planned date for monitoring without overwriting it', function () {
    $this->travelTo(pmmsToday()->setTime(9, 0));
    seedAccessControl();

    $engineer = User::factory()->projectEngineer()->create();
    $project = Project::factory()->ownedBy($engineer)->create([
        'status' => ProjectStatus::Ongoing,
        'planned_start_date' => '2026-01-05',
        'planned_finish_date' => '2026-05-01',
        'revised_finish_date' => pmmsFinishDate(35),
    ]);

    Sanctum::actingAs($engineer);

    $this->getJson("/api/v1/pmms/projects/{$project->id}")
        ->assertOk()
        ->assertJsonPath('data.indicator', 'critical')
        ->assertJsonPath('data.days_remaining', 35)
        ->assertJsonPath('data.effective_finish_date', pmmsFinishDate(35))
        ->assertJsonPath('data.planned_finish_date', '2026-05-01');

    expect($project->fresh()->planned_finish_date->toDateString())->toBe('2026-05-01');
});

test('project lists filter by the computed indicator', function () {
    $this->travelTo(pmmsToday()->setTime(9, 0));
    seedAccessControl();

    $engineer = User::factory()->projectEngineer()->create();

    $normal = Project::factory()->ownedBy($engineer)->create([
        'status' => ProjectStatus::NotStarted,
        'planned_finish_date' => pmmsFinishDate(36),
    ]);
    $critical = Project::factory()->ownedBy($engineer)->create([
        'status' => ProjectStatus::Ongoing,
        'planned_finish_date' => pmmsFinishDate(35),
    ]);
    $overdue = Project::factory()->ownedBy($engineer)->create([
        'status' => ProjectStatus::Ongoing,
        'planned_finish_date' => pmmsFinishDate(-1),
    ]);
    Project::factory()->ownedBy($engineer)->completed()->create([
        'planned_finish_date' => pmmsFinishDate(-10),
    ]);
    Project::factory()->ownedBy($engineer)->paused()->create([
        'planned_finish_date' => pmmsFinishDate(-10),
    ]);

    Sanctum::actingAs($engineer);

    $this->getJson('/api/v1/pmms/projects?indicator=critical')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $critical->id);

    $this->getJson('/api/v1/pmms/projects?indicator=overdue')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $overdue->id);

    $this->getJson('/api/v1/pmms/projects?indicator=normal')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $normal->id);

    $this->getJson('/api/v1/pmms/projects?indicator=not-a-real-state')
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['indicator']]);
});

test('dashboard numbers equal report numbers for the same filters', function () {
    $this->travelTo(pmmsToday()->setTime(9, 0));
    seedAccessControl();

    $admin = User::factory()->admin()->create();
    $engineer = User::factory()->projectEngineer()->create();
    $category = ProjectCategory::factory()->create();

    Project::factory()->ownedBy($engineer)->create([
        'project_category_id' => $category->id,
        'status' => ProjectStatus::NotStarted,
        'planned_finish_date' => pmmsFinishDate(36),
    ]);
    Project::factory()->ownedBy($engineer)->create([
        'project_category_id' => $category->id,
        'status' => ProjectStatus::Ongoing,
        'planned_finish_date' => pmmsFinishDate(20),
    ]);
    Project::factory()->ownedBy($engineer)->create([
        'status' => ProjectStatus::Ongoing,
        'planned_finish_date' => pmmsFinishDate(-2),
    ]);
    Project::factory()->ownedBy($engineer)->completed()->create([
        'planned_finish_date' => pmmsFinishDate(-2),
    ]);
    Project::factory()->ownedBy($engineer)->paused()->create([
        'planned_finish_date' => pmmsFinishDate(-2),
    ]);
    Project::factory()->ownedBy($engineer)->terminated()->create([
        'planned_finish_date' => pmmsFinishDate(-2),
    ]);

    Sanctum::actingAs($admin);

    $dashboard = $this->getJson('/api/v1/pmms/dashboard')->assertOk()->json();

    $indicatorReport = $this->getJson('/api/v1/pmms/reports/projects?group_by=indicator')
        ->assertOk()
        ->assertJsonPath('group_by', 'indicator')
        ->json();

    $statusReport = $this->getJson('/api/v1/pmms/reports/projects?group_by=status')
        ->assertOk()
        ->assertJsonPath('group_by', 'status')
        ->json();

    $reportIndicators = collect($indicatorReport['groups'])
        ->mapWithKeys(fn (array $group): array => [$group['key'] => $group['count']])
        ->all();

    $reportStatuses = collect($statusReport['groups'])
        ->mapWithKeys(fn (array $group): array => [$group['key'] => $group['count']])
        ->all();

    expect($dashboard['counts']['total'])->toBe(6)
        ->and($dashboard['counts']['by_indicator'])->toBe($reportIndicators)
        ->and($dashboard['counts']['by_status'])->toBe($reportStatuses)
        ->and($statusReport['totals']['count'])->toBe($dashboard['counts']['total'])
        ->and(array_sum($reportStatuses))->toBe(6)
        ->and(array_sum($reportIndicators))->toBe(6);

    $this->getJson('/api/v1/pmms/reports/projects?group_by=invalid')
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['group_by']]);
});

test('a project engineer dashboard and reports are limited to his own projects', function () {
    $this->travelTo(pmmsToday()->setTime(9, 0));
    seedAccessControl();

    $engineer = User::factory()->projectEngineer()->create();
    $otherEngineer = User::factory()->projectEngineer()->create();

    $own = Project::factory()->ownedBy($engineer)->create([
        'status' => ProjectStatus::Ongoing,
        'planned_finish_date' => pmmsFinishDate(30),
    ]);
    $secondOwn = Project::factory()->ownedBy($engineer)->create([
        'status' => ProjectStatus::NotStarted,
        'planned_finish_date' => pmmsFinishDate(-1),
    ]);

    Project::factory()->ownedBy($otherEngineer)->count(3)->create([
        'status' => ProjectStatus::Ongoing,
        'planned_finish_date' => pmmsFinishDate(10),
    ]);

    Sanctum::actingAs($engineer);

    $this->getJson('/api/v1/pmms/dashboard')
        ->assertOk()
        ->assertJsonPath('counts.total', 2)
        ->assertJsonPath('counts.by_indicator.critical', 1)
        ->assertJsonPath('counts.by_indicator.overdue', 1);

    $this->getJson('/api/v1/pmms/reports/projects')
        ->assertOk()
        ->assertJsonPath('totals.count', 2);

    $this->getJson('/api/v1/pmms/projects?assigned_pe_id='.$otherEngineer->id)
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $own->id)
        ->assertJsonPath('data.1.id', $secondOwn->id);

    $this->getJson('/api/v1/pmms/dashboard?assigned_pe_id='.$otherEngineer->id)
        ->assertOk()
        ->assertJsonPath('counts.total', 2);
});

test('project reports stream a csv export and write an audit row', function () {
    $this->travelTo(pmmsToday()->setTime(9, 0));
    seedAccessControl();

    $admin = User::factory()->admin()->create();

    $first = Project::factory()->create([
        'project_name' => 'Bridge Rehabilitation',
        'implementation_year' => 2026,
        'contractor' => 'Bridge Builders Inc.',
        'status' => ProjectStatus::Ongoing,
        'planned_finish_date' => pmmsFinishDate(20),
    ]);
    Project::factory()->create([
        'project_name' => 'Drainage Improvement',
        'implementation_year' => 2025,
        'status' => ProjectStatus::Completed,
    ]);

    Sanctum::actingAs($admin);

    $response = $this->get('/api/v1/pmms/reports/projects/export?implementation_year=2026');

    $response->assertOk();

    expect($response->headers->get('content-type'))->toContain('text/csv');

    $content = $response->streamedContent();

    expect($content)
        ->toContain('Project ID')
        ->toContain('Bridge Rehabilitation')
        ->not->toContain('Drainage Improvement');

    $this->assertDatabaseHas('audit_logs', [
        'user_id' => $admin->id,
        'module' => 'pmms',
        'action' => 'project_report_exported',
    ]);

    $audit = AuditLog::query()
        ->where('action', 'project_report_exported')
        ->firstOrFail();

    expect($audit->new_values['format'])->toBe('csv')
        ->and($audit->new_values['row_count'])->toBe(1)
        ->and($audit->context['filters']['implementation_year'])->toBe(2026);

    expect($first->fresh()->status)->toBe(ProjectStatus::Ongoing);
});

test('viewers need explicit pmms permissions for dashboard and reports', function () {
    seedAccessControl();

    $viewer = User::factory()->viewer()->create();

    Sanctum::actingAs($viewer);

    $this->getJson('/api/v1/pmms/dashboard')->assertForbidden();
    $this->getJson('/api/v1/pmms/reports/projects')->assertForbidden();
    $this->get('/api/v1/pmms/reports/projects/export')->assertForbidden();

    $permissionIds = Permission::query()
        ->whereIn('name', [
            PermissionName::PmmsProjectsViewAll->value,
            PermissionName::PmmsDashboardView->value,
            PermissionName::PmmsReportsView->value,
        ])
        ->pluck('id')
        ->all();

    $viewer->grantedPermissions()->attach($permissionIds);

    Project::factory()->create();

    $this->getJson('/api/v1/pmms/dashboard')->assertOk();
    $this->getJson('/api/v1/pmms/reports/projects')->assertOk();
    $this->get('/api/v1/pmms/reports/projects/export')->assertOk();
});

test('project read filters are applied by the backend', function () {
    seedAccessControl();

    $admin = User::factory()->admin()->create();
    $engineer = User::factory()->projectEngineer()->create();
    $category = ProjectCategory::factory()->create(['name' => 'Road Construction']);

    $match = Project::factory()->ownedBy($engineer)->create([
        'project_category_id' => $category->id,
        'implementation_year' => 2025,
        'status' => ProjectStatus::Ongoing,
        'fund_source' => 'National Government',
        'region' => 'Region III',
        'lgu' => 'City of San Fernando',
        'contractor' => 'Alpha Builders',
    ]);

    Project::factory()->ownedBy($engineer)->create([
        'implementation_year' => 2024,
        'fund_source' => 'LGU',
    ]);

    Sanctum::actingAs($admin);

    $this->getJson('/api/v1/pmms/projects?implementation_year=2025&project_category_id='.$category->id)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $match->id);

    $this->getJson('/api/v1/pmms/projects?fund_source=National&region=Region%20III&contractor=Alpha')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $match->id);
});

test('invalid status and indicator filter values are rejected', function () {
    seedAccessControl();

    $admin = User::factory()->admin()->create();

    Sanctum::actingAs($admin);

    $this->getJson('/api/v1/pmms/projects?status=unknown')
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['status']]);

    $this->getJson('/api/v1/pmms/dashboard?indicator=unknown')
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['indicator']]);
});

test('terminated and completed projects are excluded from critical and overdue filters', function () {
    $this->travelTo(pmmsToday()->setTime(9, 0));
    seedAccessControl();

    $admin = User::factory()->admin()->create();

    Project::factory()->completed()->create([
        'planned_finish_date' => pmmsFinishDate(-1),
    ]);
    Project::factory()->terminated()->create([
        'planned_finish_date' => pmmsFinishDate(-1),
    ]);
    Project::factory()->paused()->create([
        'planned_finish_date' => pmmsFinishDate(-1),
    ]);
    $overdue = Project::factory()->create([
        'status' => ProjectStatus::Ongoing,
        'planned_finish_date' => pmmsFinishDate(-1),
    ]);

    Sanctum::actingAs($admin);

    $this->getJson('/api/v1/pmms/projects?indicator=overdue')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $overdue->id);

    $this->getJson('/api/v1/pmms/projects?indicator=critical')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('report group by category and assigned project engineer counts all filtered projects', function () {
    seedAccessControl();

    $admin = User::factory()->admin()->create();
    $engineer = User::factory()->projectEngineer()->create();
    $category = ProjectCategory::factory()->create(['name' => 'Bridge Construction']);

    Project::factory()->ownedBy($engineer)->count(2)->create([
        'project_category_id' => $category->id,
    ]);
    Project::factory()->create();

    Sanctum::actingAs($admin);

    $categoryReport = $this->getJson('/api/v1/pmms/reports/projects?group_by=category')
        ->assertOk()
        ->json();

    $bridgeGroup = collect($categoryReport['groups'])->firstWhere('key', $category->id);

    expect($bridgeGroup)->not->toBeNull()
        ->and($bridgeGroup['label'])->toBe('Bridge Construction')
        ->and($bridgeGroup['count'])->toBe(2);

    $engineerReport = $this->getJson('/api/v1/pmms/reports/projects?group_by=assigned_pe')
        ->assertOk()
        ->json();

    $engineerGroup = collect($engineerReport['groups'])->firstWhere('key', $engineer->id);

    expect($engineerGroup)->not->toBeNull()
        ->and($engineerGroup['label'])->toBe($engineer->full_name)
        ->and($engineerGroup['count'])->toBe(2);
});
