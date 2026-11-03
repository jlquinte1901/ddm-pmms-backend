<?php

namespace Database\Seeders;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionSeeder extends Seeder
{
    /**
     * Seed the baseline permission matrix without removing later grants made
     * by a Super Admin.
     */
    public function run(): void
    {
        $guard = config('auth.defaults.guard', 'web');
        foreach (RoleName::cases() as $roleName) {
            $role = Role::query()->where('name', $roleName->value)->where('guard_name', $guard)->first();

            if ($role === null) {
                continue;
            }

            $permissionNames = array_map(
                fn (PermissionName $permission): string => $permission->value,
                $this->permissionsFor($roleName),
            );

            $permissionIds = Permission::query()
                ->where('guard_name', $guard)
                ->whereIn('name', $permissionNames)
                ->pluck('id')
                ->all();

            $role->syncPermissions($permissionIds);
        }
    }

    /**
     * @return array<int, PermissionName>
     */
    private function permissionsFor(RoleName $role): array
    {
        return match ($role) {
            RoleName::SuperAdmin => [
                PermissionName::UsersManage,
                PermissionName::RolesManage,
                PermissionName::AuditView,
                PermissionName::DdmDocumentsView,
                PermissionName::DdmDocumentsUpload,
                PermissionName::DdmDocumentsArchive,
                PermissionName::DdmDocumentsDelete,
                PermissionName::DdmDownloadsRequest,
                PermissionName::DdmDownloadsDirect,
                PermissionName::DdmDownloadsApproveRestricted,
                PermissionName::DdmDownloadsApproveConfidential,
                PermissionName::DdmCategoriesManage,
                PermissionName::DdmReportsView,
                PermissionName::PmmsCategoriesManage,
                PermissionName::PmmsProjectsViewAll,
                PermissionName::PmmsProjectsViewOwn,
                PermissionName::PmmsProjectsCreate,
                PermissionName::PmmsProjectsManageOwn,
                PermissionName::PmmsProjectsAdminister,
                PermissionName::PmmsDashboardView,
                PermissionName::PmmsReportsView,
            ],
            RoleName::Admin => [
                PermissionName::DdmDocumentsView,
                PermissionName::DdmDocumentsUpload,
                PermissionName::DdmDocumentsArchive,
                PermissionName::DdmDocumentsDelete,
                PermissionName::DdmDownloadsRequest,
                PermissionName::DdmDownloadsApproveRestricted,
                PermissionName::DdmDownloadsApproveConfidential,
                PermissionName::DdmCategoriesManage,
                PermissionName::DdmReportsView,
                PermissionName::PmmsCategoriesManage,
                PermissionName::PmmsProjectsViewAll,
                PermissionName::PmmsProjectsAdminister,
                PermissionName::PmmsDashboardView,
                PermissionName::PmmsReportsView,
            ],
            RoleName::ProjectEngineer => [
                PermissionName::DdmDocumentsView,
                PermissionName::DdmDownloadsRequest,
                PermissionName::PmmsProjectsViewOwn,
                PermissionName::PmmsProjectsCreate,
                PermissionName::PmmsProjectsManageOwn,
                PermissionName::PmmsDashboardView,
            ],
            RoleName::Viewer => [],
        };
    }
}
