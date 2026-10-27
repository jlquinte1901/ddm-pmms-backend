<?php

use App\Enums\DocumentCategoryScope;
use App\Enums\PermissionName;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DocumentCategoryController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\DocumentDownloadController;
use App\Http\Controllers\DocumentDownloadRequestController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\RolePermissionController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserPermissionController;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\DocumentDownloadRequest;
use Illuminate\Support\Facades\Route;

Route::bind('ddmCategory', function (string $value): DocumentCategory {
    return DocumentCategory::query()
        ->whereKey($value)
        ->where('scope', DocumentCategoryScope::Ddm->value)
        ->firstOrFail();
});

Route::bind('ddmDocument', function (string $value): Document {
    return Document::query()
        ->withoutProject()
        ->whereKey($value)
        ->firstOrFail();
});

Route::bind('ddmDeletedDocument', function (string $value): Document {
    return Document::withTrashed()
        ->withoutProject()
        ->whereKey($value)
        ->firstOrFail();
});

Route::bind('ddmDownloadRequest', function (string $value): DocumentDownloadRequest {
    return DocumentDownloadRequest::query()
        ->whereKey($value)
        ->whereHas('document', fn ($query) => $query->withoutProject())
        ->firstOrFail();
});

Route::prefix('v1')->group(function (): void {
    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:login')
        ->name('auth.login');

    Route::middleware(['auth:sanctum', 'active'])->group(function (): void {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');

        Route::prefix('ddm')->group(function (): void {
            Route::middleware('permission:'.PermissionName::DdmDocumentsView->value)->group(function (): void {
                Route::get('categories', [DocumentCategoryController::class, 'index'])
                    ->name('ddm.categories.index');
                Route::get('categories/{ddmCategory}', [DocumentCategoryController::class, 'show'])
                    ->name('ddm.categories.show');
                Route::get('documents', [DocumentController::class, 'index'])
                    ->name('ddm.documents.index');
                Route::get('documents/{ddmDocument}', [DocumentController::class, 'show'])
                    ->name('ddm.documents.show');
                Route::get('documents/{ddmDocument}/download', [DocumentDownloadController::class, 'download'])
                    ->name('ddm.documents.download');
            });

            Route::middleware('permission:'.implode(',', [
                PermissionName::DdmDocumentsView->value,
                PermissionName::DdmDownloadsRequest->value,
                PermissionName::DdmDownloadsApproveRestricted->value,
                PermissionName::DdmDownloadsApproveConfidential->value,
            ]))->group(function (): void {
                Route::get('download-requests', [DocumentDownloadRequestController::class, 'index'])
                    ->name('ddm.download-requests.index');
                Route::get('download-requests/{ddmDownloadRequest}', [DocumentDownloadRequestController::class, 'show'])
                    ->name('ddm.download-requests.show');
            });

            Route::middleware([
                'permission:'.PermissionName::DdmDocumentsView->value,
                'permission:'.PermissionName::DdmDownloadsRequest->value,
            ])->group(function (): void {
                Route::post('documents/{ddmDocument}/download-requests', [DocumentDownloadRequestController::class, 'store'])
                    ->name('ddm.documents.download-requests.store');
            });

            Route::middleware('permission:'.implode(',', [
                PermissionName::DdmDocumentsView->value,
                PermissionName::DdmDownloadsApproveRestricted->value,
                PermissionName::DdmDownloadsApproveConfidential->value,
            ]))->group(function (): void {
                Route::post('download-requests/{ddmDownloadRequest}/approve', [DocumentDownloadRequestController::class, 'approve'])
                    ->name('ddm.download-requests.approve');
                Route::post('download-requests/{ddmDownloadRequest}/deny', [DocumentDownloadRequestController::class, 'deny'])
                    ->name('ddm.download-requests.deny');
            });

            Route::middleware('permission:'.PermissionName::DdmCategoriesManage->value)->group(function (): void {
                Route::post('categories', [DocumentCategoryController::class, 'store'])
                    ->name('ddm.categories.store');
                Route::match(['put', 'patch'], 'categories/{ddmCategory}', [DocumentCategoryController::class, 'update'])
                    ->name('ddm.categories.update');
            });

            Route::middleware('permission:'.PermissionName::DdmDocumentsUpload->value)->group(function (): void {
                Route::post('documents', [DocumentController::class, 'store'])
                    ->name('ddm.documents.store');
                Route::match(['put', 'patch'], 'documents/{ddmDocument}', [DocumentController::class, 'update'])
                    ->name('ddm.documents.update');
                Route::post('documents/{ddmDocument}/versions', [DocumentController::class, 'storeVersion'])
                    ->name('ddm.documents.versions.store');
            });

            Route::middleware('permission:'.PermissionName::DdmDocumentsArchive->value)->group(function (): void {
                Route::post('documents/{ddmDocument}/archive', [DocumentController::class, 'archive'])
                    ->name('ddm.documents.archive');
                Route::post('documents/{ddmDocument}/unarchive', [DocumentController::class, 'unarchive'])
                    ->name('ddm.documents.unarchive');
            });

            Route::middleware('permission:'.PermissionName::DdmDocumentsDelete->value)->group(function (): void {
                Route::delete('documents/{ddmDocument}', [DocumentController::class, 'destroy'])
                    ->name('ddm.documents.destroy');
                Route::post('documents/{ddmDeletedDocument}/restore', [DocumentController::class, 'restore'])
                    ->name('ddm.documents.restore');
            });
        });

        Route::middleware('permission:'.PermissionName::UsersManage->value)->group(function (): void {
            Route::apiResource('users', UserController::class);
            Route::post('users/{user}/restore', [UserController::class, 'restore'])
                ->withTrashed()
                ->name('users.restore');
            Route::post('users/{user}/activate', [UserController::class, 'activate'])
                ->name('users.activate');
            Route::post('users/{user}/deactivate', [UserController::class, 'deactivate'])
                ->name('users.deactivate');
            Route::put('users/{user}/permissions', [UserPermissionController::class, 'update'])
                ->name('users.permissions.update');
        });

        Route::middleware('permission:'.PermissionName::RolesManage->value)->group(function (): void {
            Route::apiResource('roles', RoleController::class);
            Route::put('roles/{role}/permissions', [RolePermissionController::class, 'update'])
                ->name('roles.permissions.update');

            Route::apiResource('permissions', PermissionController::class);
        });

        Route::middleware('permission:'.PermissionName::AuditView->value)->group(function (): void {
            Route::get('audit-logs', [AuditLogController::class, 'index'])
                ->name('audit-logs.index');
            Route::get('audit-logs/{auditLog}', [AuditLogController::class, 'show'])
                ->name('audit-logs.show');
        });
    });
});
