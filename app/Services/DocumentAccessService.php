<?php

namespace App\Services;

use App\Enums\DocumentAccessLevel;
use App\Enums\DownloadRequestStatus;
use App\Enums\PermissionName;
use App\Models\Document;
use App\Models\DocumentDownloadRequest;
use App\Models\User;

final class DocumentAccessService
{
    public function __construct(private readonly AuthorizationService $authorization) {}

    /**
     * @param  array<int, int>|null  $approvedDocumentIds
     */
    public function canDownload(User $user, Document $document, ?array $approvedDocumentIds = null): bool
    {
        if ($document->project_id !== null || $document->trashed()) {
            return false;
        }

        if (! $this->authorization->hasPermission($user, PermissionName::DdmDocumentsView)) {
            return false;
        }

        $accessLevel = $document->effectiveAccessLevel();

        return match ($accessLevel) {
            DocumentAccessLevel::Open => true,
            DocumentAccessLevel::Restricted => $this->authorization->hasPermission($user, PermissionName::DdmDownloadsApproveRestricted)
                || $this->hasApprovedRequest($user, $document, $approvedDocumentIds),
            DocumentAccessLevel::Confidential => $this->authorization->hasPermission($user, PermissionName::DdmDownloadsApproveConfidential)
                || $this->hasApprovedRequest($user, $document, $approvedDocumentIds),
        };
    }

    /**
     * @param  array<int, int>  $documentIds
     * @return array<int, int>
     */
    public function approvedDocumentIds(User $user, array $documentIds): array
    {
        if ($documentIds === []) {
            return [];
        }

        return DocumentDownloadRequest::query()
            ->whereIn('document_id', $documentIds)
            ->where('requested_by', $user->getKey())
            ->where('status', DownloadRequestStatus::Approved)
            ->where('expires_at', '>', now())
            ->pluck('document_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * @param  array<int, int>|null  $approvedDocumentIds
     */
    private function hasApprovedRequest(User $user, Document $document, ?array $approvedDocumentIds): bool
    {
        if ($approvedDocumentIds !== null) {
            return in_array($document->getKey(), $approvedDocumentIds, true);
        }

        return DocumentDownloadRequest::query()
            ->where('document_id', $document->getKey())
            ->where('requested_by', $user->getKey())
            ->where('status', DownloadRequestStatus::Approved)
            ->where('expires_at', '>', now())
            ->exists();
    }
}
