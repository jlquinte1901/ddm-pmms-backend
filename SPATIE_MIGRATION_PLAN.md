# Spatie Laravel Permission Migration Plan (Approach 1 - Incremental)

## 1) Compatibility checks (completed)
- Laravel: 13.36.0 ✓
- PHP: 8.5.0 (per environment context) ✓
- Sanctum: ^4.3 (installed) ✓
- Auth config: `defaults.guard = web` (session driver). API routes use `auth:sanctum` middleware. Spatie will use guard `web` by default via `config/permission.php` (to be published). This is compatible.
- Current DB: MySQL `ddm-pmms` configured in `.env` (connection refused in this environment - MySQL not running). Backup/migrations must run in proper environment.
- Spatie package: Not installed. Recommended version: `spatie/laravel-permission ^6.21` (supports Laravel 13). Will verify with `composer show` after install if needed, but ^6.21 is correct for Laravel 13.

## 2) Backup (required before migrations)
**Action (must be done before running any migrations):**
```bash
# Create backup directory outside repo
mkdir -p /c/xampp/htdocs/ddm-pmms-backups  # or equivalent outside repo

# Dump full database with routines/triggers/events
"C:/xampp/mysql/bin/mysqldump.exe" -u root ddm-pmms --routines --triggers --events > "C:/xampp/htdocs/ddm-pmms-backups/ddm-pmms-backup-$(date +%Y%m%d-%H%M%S).sql"

# Generate SHA256 hash
sha256sum "C:/xampp/htdocs/ddm-pmms-backups/ddm-pmms-backup-*.sql" > "C:/xampp/htdocs/ddm-pmms-backups/ddm-pmms-backup-$(date +%Y%m%d-%H%M%S).sha256"
```
- Store backup **outside the repository** (e.g., `C:/xampp/htdocs/ddm-pmms-backups/`)
- **Do not commit** backup files
- Also snapshot `migrations` table if desired (mysqldump includes it)
- First run must be on a **copy** of the database

## 3) Changes required (code/config)
### (a) Convert any open access levels to restricted
- `app/Enums/DocumentAccessLevel.php`: Remove or deprecate `Open` case. Change to only `Restricted` and `Confidential` (or keep cases but ensure no usage). Better to remove `Open` and update all usages.
- `app/Models/DocumentCategory.php`, `app/Models/Document.php`: Update casts/defaults
- `database/factories/DocumentCategoryFactory.php`: Change default from `Open` to `Restricted`
- `app/Services/DocumentAccessService.php`: Remove `Open => true` branch; Open documents must now require approval like restricted/confidential per new rules? Wait - new rules say "Every document is treated as sensitive, so there is no open level. Every download needs an approved, unexpired request. Restricted requests are decided by any Admin, and confidential requests by Super Admin only. Nobody approves their own request, and approvals expire. Only two cases download directly, both audit-logged: Super Admin, and the owning PE for documents of his own projects. Being able to view a document never allows downloading it. Admin, Viewers, and other PEs always request." So NO direct downloads except Super Admin (with ddm.downloads.direct permission) and owning PE for documents of his own projects. All others need requests regardless of level? And levels are only restricted/confidential now.
- `app/Services/DocumentDownloadRequestService.php`: Remove Open handling (no direct download for open, and open can't be requested? Or treat as restricted)
- `app/Http/Controllers/DocumentDownloadController.php`: Update to respect new direct download rules
- Tests: Update tests that assume open documents can be downloaded directly

### (b) Add `ddm.downloads.direct` permission
- Add to `app/Enums/PermissionName.php`: `case DdmDownloadsDirect = 'ddm.downloads.direct';` (module ddm)
- Update `PermissionSeeder` will pick it up automatically
- Update authorization logic to grant direct download when user has `ddm.downloads.direct` OR (owning PE for documents of his own projects - PMMS documents with project_id owned by PE). Both must be audit-logged.

### (c) No `Gate::before` bypass for Super Admin
- Remove any `Gate::before` that grants all abilities to super admin. Current codebase uses custom middleware/services - need to ensure no such bypass exists. Check `AuthServiceProvider`, `AppServiceProvider`, etc. Also ensure Super Admin gets permissions explicitly (via role/permissions).

### (d) Guard name
- Publish spatie permission config, set `'guard_name' => 'web'` (default) to match Sanctum usage pattern

### (e) Category seeders must use dev-only placeholders
- Review any category seeders - ensure dev-only and not production data

## 4) Exact old-to-Spatie mapping

### Roles (old -> Spatie)
| Old Role (roles table) | Old ID | Spatie Role (name/guard) | Label |
|---|---|---|---|
| super_admin | 1 | super_admin (web) | Super Admin |
| admin | 2 | admin (web) | Admin |
| project_engineer | 3 | project_engineer (web) | Project Engineer |
| viewer | 4 | viewer (web) | Viewer |

### Permissions (old -> Spatie) - add ddm.downloads.direct
All existing from `PermissionName` enum map 1:1 by name. Add:
- `ddm.downloads.direct` (module: ddm)

Current permissions (23): users.manage, roles.manage, audit.view, ddm.documents.view, ddm.documents.upload, ddm.documents.archive, ddm.documents.delete, ddm.downloads.request, ddm.downloads.approve-restricted, ddm.downloads.approve-confidential, ddm.categories.manage, ddm.reports.view, pmms.projects.view-all, pmms.projects.view-own, pmms.projects.create, pmms.projects.manage-own, pmms.projects.administer, pmms.dashboard.view, pmms.reports.view, pmms.categories.manage

### Role-Permission mappings (preserve exactly)
From `permission_role` table as-is - migrate to `role_has_permissions`.

### User-Role mappings (preserve exactly)
For each user in `users` with `role_id`, find old role name, assign corresponding Spatie role via `model_has_roles` (model_type = App\Models\User, model_id = user.id, role_id = spatie role id). One role per user enforced.

### User-Permission direct grants (preserve exactly)
From `permission_user` table (user_id, permission_id, granted_by) - migrate to `model_has_permissions` (model_type = App\Models\User, model_id = user.id, permission_id = spatie permission id). Note `granted_by` - Spatie's pivot doesn't have it by default; we need to either add column to pivot or store elsewhere. Check if needed - custom code uses it? Let us look up usage. `User::grantedPermissions()` has `withPivot('granted_by')` and `UserPermissionController` might use it. We need to preserve it.

### Mapping table for role_id restoration (down())
Create a temporary mapping table (e.g., `spatie_migration_role_map`) during migration to store: `user_id`, `old_role_id`, `old_role_name`. This allows full restoration in `down()`.

## 5) Migration list (proposed - requires approval before creation)
**DO NOT CREATE OR RUN YET**

### Migration 1: Prepare backup mapping and add ddm.downloads.direct support prep
- Create mapping table `migration_role_backup` with `user_id`, `role_id` (old), `role_name`
- Populate from current users/roles
- Add `ddm.downloads.direct` to permissions table if needed? Or handle in later migration. Better to add via seeder logic or migration.

### Migration 2: Install/configure Spatie Permission structure
- Publish and run Spatie migrations (create `roles`, `permissions`, `model_has_permissions`, `model_has_roles`, `role_has_permissions` tables with guard_name)
- Note: We have existing `roles` and `permissions` tables with different structure. Need to be careful about table names/conflicts. Spatie by default uses same table names. We may need to:
  1. Rename old tables to backup (e.g., `roles_old`, `permissions_old`, `permission_role_old`, `permission_user_old`)
  2. Run Spatie migrations to create new tables
  3. Migrate data
  4. Or modify existing tables to match Spatie structure

### Migration 3: Migrate data
- Migrate roles from `roles_old` to Spatie `roles` (guard_name='web')
- Migrate permissions from `permissions_old` to Spatie `permissions` (add ddm.downloads.direct, guard_name='web')
- Migrate role-permission from `permission_role_old` to `role_has_permissions`
- Migrate user roles from `users.role_id` (using mapping) to `model_has_roles`
- Migrate direct permissions from `permission_user_old` to `model_has_permissions` (preserve granted_by - may need to add column to pivot)

### Migration 4: Update schema
- Drop `users.role_id` column (after migration) - but need to keep mapping for down()
- Drop old backup tables or keep until verified
- Update foreign keys/indexes as needed
- Convert access levels: update all documents/categories with access_level 'open' to 'restricted' (and change enum/defaults)

### Migration 5: Down() requirements
Every migration affecting `users.role_id` must have `down()` that fully restores it via mapping table. So we must:
- In migrations that drop/modify role_id, store mapping in `migration_role_backup` first
- In down(), recreate column, restore values from mapping, drop mapping table if desired

## 6) First run procedure
1. Create copy of database (e.g., `ddm-pmms-copy`) 
2. Run migrations on copy first
3. Test thoroughly
4. Only run on production DB after approval and successful copy test

## Approval needed
- Migration list above
- Approach to handle Spatie table conflicts (rename old vs migrate in place)
- Handling of `granted_by` in permission_user pivot (add column to Spatie pivot?)
- Confirm exact commands for backup in this environment
