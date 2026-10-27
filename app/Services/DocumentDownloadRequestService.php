<?php

namespace App\Services;

use App\Enums\DocumentAccessLevel;
use App\Enums\DownloadRequestStatus;
use App\Enums\PermissionName;
use App\Exceptions\StateConflictException;
use App\Models\Document;
use App\Models\DocumentDownloadRequest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

final class DocumentDownloadRequestService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly AuthorizationService $authorization,
        private readonly DocumentAccessService $access,
    ) {}

    public function create(Document $document, string $reason, User $requester): DocumentDownloadRequest
    {
        return DB::transaction(function () use ($document, $reason, $requester): DocumentDownloadRequest {
            $document = Document::query()
                ->withoutProject()
                ->with('category')
                ->whereKey($document->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $accessLevel = $document->effectiveAccessLevel();

            if ($accessLevel === DocumentAccessLevel::Open) {
                throw new StateConflictException('Open documents can be downloaded directly without a request.');
            }

            if ($this->access->canDownload($requester, $document)) {
                throw new StateConflictException('This document is already available to download.');
            }

            $lapseThreshold = now()->subDays((int) config('documents.download.pending_lapse_days', 14));

            $pendingExists = DocumentDownloadRequest::query()
                ->where('document_id', $document->getKey())
                ->where('requested_by', $requester->getKey())
                ->where('status', DownloadRequestStatus::Pending)
                ->where('created_at', '>', $lapseThreshold)
                ->exists();

            if ($pendingExists) {
                throw new StateConflictException('A pending download request already exists for this document.');
            }

            $downloadRequest = new DocumentDownloadRequest;
            $downloadRequest->fill([
                'document_id' => $document->getKey(),
                'reason' => $reason,
            ]);
            $downloadRequest->forceFill([
                'requested_by' => $requester->getKey(),
                'status' => DownloadRequestStatus::Pending,
            ])->save();

            $this->audit->record(
                module: 'ddm',
                action: 'download_requested',
                auditable: $downloadRequest,
                newValues: ['status' => DownloadRequestStatus::Pending->value],
                context: [
                    'document_id' => $document->getKey(),
                    'access_level' => $accessLevel->value,
                ],
                actor: $requester,
            );

            return $this->loadRelations($downloadRequest);
        });
    }

    public function approve(
        DocumentDownloadRequest $downloadRequest,
        ?int $approvalDays,
        ?string $decisionNote,
        User $approver,
    ): DocumentDownloadRequest {
        try {
            return DB::transaction(function () use ($downloadRequest, $approvalDays, $decisionNote, $approver): DocumentDownloadRequest {
                $downloadRequest = $this->lockRequest($downloadRequest);
                $document = $this->documentFor($downloadRequest);
                $this->assertDecidable($downloadRequest, $document, $approver);

                $days = $this->approvalDays($approvalDays);
                $decidedAt = now();
                $expiresAt = $decidedAt->copy()->addDays($days);
                $oldValues = $this->decisionSnapshot($downloadRequest);

                $downloadRequest->forceFill([
                    'status' => DownloadRequestStatus::Approved,
                    'decided_by' => $approver->getKey(),
                    'decided_at' => $decidedAt,
                    'expires_at' => $expiresAt,
                    'decision_note' => $decisionNote,
                ])->save();

                $this->audit->record(
                    module: 'ddm',
                    action: 'download_request_approved',
                    auditable: $downloadRequest,
                    oldValues: $oldValues,
                    newValues: $this->decisionSnapshot($downloadRequest),
                    context: [
                        'document_id' => $document->getKey(),
                        'access_level' => $document->effectiveAccessLevel()->value,
                        'approval_days' => $days,
                    ],
                    actor: $approver,
                );

                return $this->loadRelations($downloadRequest);
            });
        } catch (AuthorizationException|StateConflictException $exception) {
            $this->auditDecisionDenied($downloadRequest, $approver, 'approve', $exception);

            throw $exception;
        }
    }

    public function deny(
        DocumentDownloadRequest $downloadRequest,
        ?string $decisionNote,
        User $approver,
    ): DocumentDownloadRequest {
        try {
            return DB::transaction(function () use ($downloadRequest, $decisionNote, $approver): DocumentDownloadRequest {
                $downloadRequest = $this->lockRequest($downloadRequest);
                $document = $this->documentFor($downloadRequest);
                $this->assertDecidable($downloadRequest, $document, $approver);

                $oldValues = $this->decisionSnapshot($downloadRequest);

                $downloadRequest->forceFill([
                    'status' => DownloadRequestStatus::Denied,
                    'decided_by' => $approver->getKey(),
                    'decided_at' => now(),
                    'expires_at' => null,
                    'decision_note' => $decisionNote,
                ])->save();

                $this->audit->record(
                    module: 'ddm',
                    action: 'download_request_denied',
                    auditable: $downloadRequest,
                    oldValues: $oldValues,
                    newValues: $this->decisionSnapshot($downloadRequest),
                    context: [
                        'document_id' => $document->getKey(),
                        'access_level' => $document->effectiveAccessLevel()->value,
                    ],
                    actor: $approver,
                );

                return $this->loadRelations($downloadRequest);
            });
        } catch (AuthorizationException|StateConflictException $exception) {
            $this->auditDecisionDenied($downloadRequest, $approver, 'deny', $exception);

            throw $exception;
        }
    }

    public function canView(User $user, DocumentDownloadRequest $downloadRequest): bool
    {
        $document = $downloadRequest->document;

        if ($document === null || $document->trashed() || $document->project_id !== null) {
            return false;
        }

        if ($downloadRequest->requested_by === $user->getKey()) {
            return true;
        }

        if ($this->authorization->hasPermission($user, PermissionName::DdmDownloadsApproveConfidential)) {
            return true;
        }

        if ($this->authorization->hasPermission($user, PermissionName::DdmDownloadsApproveRestricted)) {
            return $document->effectiveAccessLevel() === DocumentAccessLevel::Restricted;
        }

        return false;
    }

    public function applyVisibilityScope(Builder $query, User $user): Builder
    {
        $query->whereHas('document', fn (Builder $documentQuery): Builder => $documentQuery->withoutProject());

        if ($this->authorization->hasPermission($user, PermissionName::DdmDownloadsApproveConfidential)) {
            return $query;
        }

        if ($this->authorization->hasPermission($user, PermissionName::DdmDownloadsApproveRestricted)) {
            return $query->where(function (Builder $query) use ($user): void {
                $query->where('requested_by', $user->getKey())
                    ->orWhereHas(
                        'document',
                        fn (Builder $documentQuery): Builder => $this->applyEffectiveAccess(
                            $documentQuery,
                            DocumentAccessLevel::Restricted,
                        ),
                    );
            });
        }

        return $query->where('requested_by', $user->getKey());
    }

    public function applyStatusFilter(Builder $query, string $status): Builder
    {
        $lapseThreshold = now()->subDays((int) config('documents.download.pending_lapse_days', 14));

        return match ($status) {
            'pending' => $query
                ->where('status', DownloadRequestStatus::Pending)
                ->where('created_at', '>', $lapseThreshold),
            'lapsed' => $query
                ->where('status', DownloadRequestStatus::Pending)
                ->where('created_at', '<=', $lapseThreshold),
            'approved' => $query
                ->where('status', DownloadRequestStatus::Approved)
                ->where('expires_at', '>', now()),
            'expired' => $query
                ->where('status', DownloadRequestStatus::Approved)
                ->where('expires_at', '<=', now()),
            'denied' => $query->where('status', DownloadRequestStatus::Denied),
            'cancelled' => $query->where('status', DownloadRequestStatus::Cancelled),
            default => $query,
        };
    }

    private function lockRequest(DocumentDownloadRequest $downloadRequest): DocumentDownloadRequest
    {
        return DocumentDownloadRequest::query()
            ->whereKey($downloadRequest->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function documentFor(DocumentDownloadRequest $downloadRequest): Document
    {
        return Document::query()
            ->withoutProject()
            ->with('category')
            ->whereKey($downloadRequest->document_id)
            ->firstOrFail();
    }

    private function assertDecidable(
        DocumentDownloadRequest $downloadRequest,
        Document $document,
        User $approver,
    ): void {
        if ($downloadRequest->status !== DownloadRequestStatus::Pending) {
            throw new StateConflictException('Only pending download requests can be decided.');
        }

        if ($downloadRequest->isLapsed()) {
            throw new StateConflictException('The download request has lapsed and can no longer be decided.');
        }

        $accessLevel = $document->effectiveAccessLevel();

        if ($accessLevel === DocumentAccessLevel::Open) {
            throw new StateConflictException('The document no longer requires a download request.');
        }

        if ($downloadRequest->requested_by === $approver->getKey()) {
            throw new AuthorizationException('You cannot decide your own download request.');
        }

        $permission = match ($accessLevel) {
            DocumentAccessLevel::Restricted => PermissionName::DdmDownloadsApproveRestricted,
            DocumentAccessLevel::Confidential => PermissionName::DdmDownloadsApproveConfidential,
            DocumentAccessLevel::Open => null,
        };

        if ($permission === null || ! $this->authorization->hasPermission($approver, $permission)) {
            throw new AuthorizationException('This action is unauthorized.');
        }
    }

    private function approvalDays(?int $approvalDays): int
    {
        $default = (int) config('documents.download.default_approval_days', 7);
        $max = (int) config('documents.download.max_approval_days', 30);

        return max(1, min($approvalDays ?? $default, $max));
    }

    /**
     * @return array<string, mixed>
     */
    private function decisionSnapshot(DocumentDownloadRequest $downloadRequest): array
    {
        return [
            'status' => $downloadRequest->status->value,
            'decided_by' => $downloadRequest->decided_by,
            'decided_at' => $downloadRequest->decided_at?->toIso8601String(),
            'expires_at' => $downloadRequest->expires_at?->toIso8601String(),
            'decision_note' => $downloadRequest->decision_note,
        ];
    }

    private function auditDecisionDenied(
        DocumentDownloadRequest $downloadRequest,
        User $approver,
        string $decision,
        Throwable $exception,
    ): void {
        $this->audit->record(
            module: 'ddm',
            action: 'download_request_decision_denied',
            auditable: $downloadRequest,
            context: [
                'document_id' => $downloadRequest->document_id,
                'decision' => $decision,
                'reason' => $exception instanceof AuthorizationException ? 'not_allowed' : 'state_conflict',
                'message' => $exception->getMessage(),
            ],
            actor: $approver,
        );
    }

    private function applyEffectiveAccess(Builder $query, DocumentAccessLevel $level): Builder
    {
        return $query->where(function (Builder $query) use ($level): void {
            $query->where('access_level', $level->value)
                ->orWhere(function (Builder $query) use ($level): void {
                    $query->whereNull('access_level')
                        ->whereHas(
                            'category',
                            fn (Builder $categoryQuery): Builder => $categoryQuery
                                ->where('default_access_level', $level->value),
                        );
                });
        });
    }

    private function loadRelations(DocumentDownloadRequest $downloadRequest): DocumentDownloadRequest
    {
        return $downloadRequest->load([
            'document.category',
            'requester',
            'decider',
        ]);
    }
}
