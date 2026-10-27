<?php

namespace App\Http\Controllers;

use App\Http\Requests\DecideDocumentDownloadRequestRequest;
use App\Http\Requests\IndexDocumentDownloadRequestRequest;
use App\Http\Requests\StoreDocumentDownloadRequestRequest;
use App\Http\Resources\DocumentDownloadRequestResource;
use App\Models\Document;
use App\Models\DocumentDownloadRequest;
use App\Services\DocumentDownloadRequestService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class DocumentDownloadRequestController extends Controller
{
    public function index(
        IndexDocumentDownloadRequestRequest $request,
        DocumentDownloadRequestService $requests,
    ): AnonymousResourceCollection {
        $perPage = min(
            max($request->integer('per_page', (int) config('api.pagination.default', 15)), 1),
            (int) config('api.pagination.max', 100),
        );

        $query = DocumentDownloadRequest::query()
            ->with(['document.category', 'requester', 'decider']);

        $requests->applyVisibilityScope($query, $request->user());

        if ($request->filled('status')) {
            $requests->applyStatusFilter($query, $request->string('status')->toString());
        }

        $downloadRequests = $query
            ->when(
                $request->filled('document_id'),
                fn ($query) => $query->where('document_id', $request->integer('document_id')),
            )
            ->when(
                $request->filled('requester'),
                fn ($query) => $query->where('requested_by', $request->integer('requester')),
            )
            ->latest('id')
            ->paginate($perPage);

        return DocumentDownloadRequestResource::collection($downloadRequests);
    }

    public function show(
        Request $request,
        DocumentDownloadRequest $ddmDownloadRequest,
        DocumentDownloadRequestService $requests,
    ): DocumentDownloadRequestResource {
        $downloadRequest = $ddmDownloadRequest->load([
            'document.category',
            'requester',
            'decider',
        ]);

        if (! $requests->canView($request->user(), $downloadRequest)) {
            throw (new ModelNotFoundException)->setModel(
                DocumentDownloadRequest::class,
                [$downloadRequest->getKey()],
            );
        }

        return new DocumentDownloadRequestResource($downloadRequest);
    }

    public function store(
        StoreDocumentDownloadRequestRequest $request,
        Document $ddmDocument,
        DocumentDownloadRequestService $requests,
    ): JsonResponse {
        $downloadRequest = $requests->create(
            $ddmDocument,
            $request->validated('reason'),
            $request->user(),
        );

        return (new DocumentDownloadRequestResource($downloadRequest))
            ->response()
            ->setStatusCode(201);
    }

    public function approve(
        DecideDocumentDownloadRequestRequest $request,
        DocumentDownloadRequest $ddmDownloadRequest,
        DocumentDownloadRequestService $requests,
    ): DocumentDownloadRequestResource {
        $downloadRequest = $requests->approve(
            $ddmDownloadRequest,
            $request->filled('approval_days') ? $request->integer('approval_days') : null,
            $request->filled('decision_note')
                ? $request->string('decision_note')->toString()
                : null,
            $request->user(),
        );

        return new DocumentDownloadRequestResource($downloadRequest);
    }

    public function deny(
        DecideDocumentDownloadRequestRequest $request,
        DocumentDownloadRequest $ddmDownloadRequest,
        DocumentDownloadRequestService $requests,
    ): DocumentDownloadRequestResource {
        $downloadRequest = $requests->deny(
            $ddmDownloadRequest,
            $request->filled('decision_note')
                ? $request->string('decision_note')->toString()
                : null,
            $request->user(),
        );

        return new DocumentDownloadRequestResource($downloadRequest);
    }
}
