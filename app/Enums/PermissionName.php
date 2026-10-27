<?php

namespace App\Enums;

/**
 * The confirmed permission catalogue. Permissions are stored in the database,
 * but these cases keep authorization checks and seeding free of string typos.
 */
enum PermissionName: string
{
    case UsersManage = 'users.manage';
    case RolesManage = 'roles.manage';
    case AuditView = 'audit.view';

    case DdmDocumentsView = 'ddm.documents.view';
    case DdmDocumentsUpload = 'ddm.documents.upload';
    case DdmDocumentsArchive = 'ddm.documents.archive';
    case DdmDocumentsDelete = 'ddm.documents.delete';
    case DdmDownloadsRequest = 'ddm.downloads.request';
    case DdmDownloadsApproveRestricted = 'ddm.downloads.approve-restricted';
    case DdmDownloadsApproveConfidential = 'ddm.downloads.approve-confidential';
    case DdmCategoriesManage = 'ddm.categories.manage';
    case DdmReportsView = 'ddm.reports.view';

    case PmmsProjectsViewAll = 'pmms.projects.view-all';
    case PmmsProjectsViewOwn = 'pmms.projects.view-own';
    case PmmsProjectsCreate = 'pmms.projects.create';
    case PmmsProjectsManageOwn = 'pmms.projects.manage-own';
    case PmmsProjectsAdminister = 'pmms.projects.administer';
    case PmmsDashboardView = 'pmms.dashboard.view';
    case PmmsReportsView = 'pmms.reports.view';
    case PmmsCategoriesManage = 'pmms.categories.manage';

    public function module(): string
    {
        return match (true) {
            str_starts_with($this->value, 'ddm.') => 'ddm',
            str_starts_with($this->value, 'pmms.') => 'pmms',
            default => 'shared',
        };
    }
}
