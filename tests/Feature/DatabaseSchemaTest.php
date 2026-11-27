<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('the confirmed tables exist', function () {
    $tables = [
        'roles',
        'permissions',
        'model_has_roles',
        'model_has_permissions',
        'role_has_permissions',
        'users',
        'audit_logs',
        'personal_access_tokens',
        'document_categories',
        'documents',
        'document_versions',
        'document_download_requests',
        'project_categories',
        'projects',
        'project_updates',
        'project_history',
    ];

    $missing = array_values(array_filter($tables, fn (string $table) => ! Schema::hasTable($table)));

    expect($missing)->toBeEmpty();
});

test('users carries the columns from the confirmed schema', function () {
    expect(Schema::hasColumns('users', [
        'full_name',
        'section',
        'is_active',
        'last_login_at',
        'deleted_at',
    ]))->toBeTrue();
});

test('users can be soft deleted and restored', function () {
    $user = User::factory()->create();
    $userId = $user->id;
    $user->delete();

    expect(User::find($userId))->toBeNull()
        ->and(User::withTrashed()->findOrFail($userId)->trashed())->toBeTrue();

    User::withTrashed()->findOrFail($userId)->restore();

    expect(User::find($userId))->not->toBeNull();
});

test('append-only tables keep created_at and expose no updated_at or deleted_at column', function () {
    $forbidden = [];

    foreach (['audit_logs', 'project_history', 'document_versions', 'project_updates'] as $table) {
        if (! Schema::hasColumn($table, 'created_at')) {
            $forbidden[] = "$table.created_at missing";
        }

        foreach (['updated_at', 'deleted_at'] as $column) {
            if (Schema::hasColumn($table, $column)) {
                $forbidden[] = "$table.$column present";
            }
        }
    }

    expect($forbidden)->toBeEmpty();
});

test('effective_finish_date stores the date that decides the deadline', function (?string $revisedFinishDate, string $expected) {
    $userId = User::factory()->create()->id;
    $categoryId = DB::table('project_categories')->insertGetId([
        'name' => 'Construction',
    ]);

    $projectId = DB::table('projects')->insertGetId([
        'project_category_id' => $categoryId,
        'assigned_pe_id' => $userId,
        'created_by' => $userId,
        'implementation_year' => 2026,
        'project_name' => 'Word Cloud Road',
        'location' => 'Barangay Bonbon',
        'fund_source' => 'LGU',
        'contract_amount' => '1500000.00',
        'planned_start_date' => '2026-01-05',
        'planned_finish_date' => '2026-12-20',
        'revised_finish_date' => $revisedFinishDate,
    ]);

    $this->assertDatabaseHas('projects', [
        'id' => $projectId,
        'effective_finish_date' => $expected,
    ]);
})->with([
    'revised finish date set' => ['2027-03-15', '2027-03-15'],
    'revised finish date missing' => [null, '2026-12-20'],
]);

test('roles are assigned through model_has_roles instead of a users role_id column', function () {
    expect(Schema::hasColumn('users', 'role_id'))->toBeFalse();

    $role = Role::query()->create([
        'name' => 'project_engineer',
        'guard_name' => 'web',
    ]);
    $user = User::factory()->create();
    $user->assignRole($role);

    $this->assertDatabaseHas('model_has_roles', [
        'role_id' => $role->id,
        'model_type' => User::class,
        'model_id' => $user->id,
    ]);
});
