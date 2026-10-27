<?php

namespace Database\Seeders;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    /**
     * Seed the baseline permission matrix without removing later grants made
     * by a Super Admin.
     */
    public function run(): void
    {
        foreach (RoleName::cases() as $roleName) {
            $role = Role::query()->where('name', $roleName->value)->first();

            if ($role === null) {
                continue;
            }

            $permissionNames = array_map(
                fn (PermissionName $permission): string => $permission->value,
                $this->permissionsFor($roleName),
            );

            $permissionIds = Permission::query()
                ->whereIn('name', $permissionNames)
                ->pluck('id')
                ->all();

            $role->permissions()->syncWithoutDetaching($permissionIds);
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
            RoleName::Admin => [
                PermissionName::DdmDocumentsView,
                PermissionName::DdmDocumentsUpload,
                PermissionName::DdmDocumentsArchive,
                PermissionName::DdmDownloadsRequest,
                PermissionName::DdmDownloadsApproveRestricted,
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
                PermissionName::PmmsReportsView,
            ],
            RoleName::Viewer => [],
        };
    }
}
