<?php

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('the fifteen confirmed tables exist', function () {
    $tables = [
        'roles',
        'permissions',
        'permission_role',
        'permission_user',
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
        'role_id',
        'full_name',
        'section',
        'is_active',
        'last_login_at',
        'deleted_at',
    ]))->toBeTrue();
});

test('users can be soft deleted and restored', function () {
    $roleId = DB::table('roles')->insertGetId([
        'name' => 'project_engineer',
        'label' => 'Project Engineer',
    ]);
    $userId = DB::table('users')->insertGetId([
        'role_id' => $roleId,
        'full_name' => 'Juan dela Cruz',
        'email' => 'pe@example.com',
        'password' => 'secret-password',
    ]);

    $user = User::findOrFail($userId);
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
    $roleId = DB::table('roles')->insertGetId([
        'name' => 'project_engineer',
        'label' => 'Project Engineer',
    ]);
    $userId = DB::table('users')->insertGetId([
        'role_id' => $roleId,
        'full_name' => 'Juan dela Cruz',
        'email' => 'pe@example.com',
        'password' => 'secret-password',
    ]);
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

test('users rejects a row without a role', function () {
    expect(fn () => DB::table('users')->insert([
        'full_name' => 'No Role',
        'email' => 'no-role@example.com',
        'password' => 'secret-password',
    ]))->toThrow(QueryException::class);
});

test('users rejects a role that does not exist', function () {
    expect(fn () => DB::table('users')->insert([
        'role_id' => 9999,
        'full_name' => 'Unknown Role',
        'email' => 'unknown-role@example.com',
        'password' => 'secret-password',
    ]))->toThrow(QueryException::class);
});
