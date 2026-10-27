<?php

namespace App\Http\Controllers;

use App\Enums\PermissionName;
use App\Http\Requests\DeleteDocumentRequest;
use App\Http\Requests\IndexDocumentRequest;
use App\Http\Requests\RestoreDocumentRequest;
use App\Http\Requests\StoreDocumentRequest;
use App\Http\Requests\StoreDocumentVersionRequest;
use App\Http\Requests\UpdateDocumentRequest;
use App\Http\Resources\DocumentResource;
use App\Http\Resources\DocumentVersionResource;
use App\Models\Document;
use App\Models\User;
use App\Services\AuditService;
use App\Services\AuthorizationService;
use App\Services\DocumentAccessService;
use App\Services\DocumentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final class DocumentController extends Controller
{
    public function index(
        IndexDocumentRequest $request,
        DocumentAccessService $access,
        AuthorizationService $authorization,
        AuditService $audit,
    ): AnonymousResourceCollection {
        $user = $request->user();
        $state = (string) $request->input('state', Document::STATE_ACTIVE);

        if ($state === Document::STATE_DELETED && ! $authorization->hasPermission($user, PermissionName::DdmDocumentsDelete)) {
            $audit->record(
                module: 'ddm',
                action: 'authorization_denied',
                context: [
                    'permission' => PermissionName::DdmDocumentsDelete->value,
                    'reason' => 'Attempted to list deleted documents.',
                ],
                actor: $user,
            );

            throw new AuthorizationException('This action is unauthorized.');
        }

        $perPage = min(
            max($request->integer('per_page', (int) config('api.pagination.default', 15)), 1),
            (int) config('api.pagination.max', 100),
        );

        $query = Document::query()
            ->withoutProject()
            ->with(['category', 'creator', 'latestVersion.uploader'])
            ->when(
                $state === Document::STATE_DELETED,
                fn (Builder $query): Builder => $query->onlyTrashed(),
            )
            ->when(
                $state === Document::STATE_ACTIVE,
                fn (Builder $query): Builder => $query->where('is_archived', false),
            )
            ->when(
                $state === Document::STATE_ARCHIVED,
                fn (Builder $query): Builder => $query->where('is_archived', true),
            )
            ->when(
                $request->filled('category_id'),
                fn (Builder $query): Builder => $query->where('category_id', $request->integer('category_id')),
            )
            ->when(
                $request->filled('uploader'),
                fn (Builder $query): Builder => $query->where('created_by', $request->integer('uploader')),
            )
            ->when(
                $request->filled('reference_no'),
                fn (Builder $query): Builder => $query->where('reference_no', 'like', '%'.trim((string) $request->input('reference_no')).'%'),
            )
            ->when(
                $request->filled('date_from'),
                fn (Builder $query): Builder => $query->whereDate('document_date', '>=', $request->date('date_from')),
            )
            ->when(
                $request->filled('date_to'),
                fn (Builder $query): Builder => $query->whereDate('document_date', '<=', $request->date('date_to')),
            )
            ->when(
                $request->filled('access_level'),
                function (Builder $query) use ($request): void {
                    $accessLevel = $request->string('access_level')->toString();

                    $query->where(function (Builder $query) use ($accessLevel): void {
                        $query->where('access_level', $accessLevel)
                            ->orWhere(function (Builder $query) use ($accessLevel): void {
                                $query->whereNull('access_level')
                                    ->whereHas('category', fn (Builder $categoryQuery): Builder => $categoryQuery
                                        ->where('default_access_level', $accessLevel));
                            });
                    });
                },
            );

        if ($request->filled('q')) {
            $search = trim((string) $request->input('q'));

            if (DB::connection()->getDriverName() === 'mysql') {
                $query->whereFullText(['title', 'description', 'reference_no'], $search);
            } else {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('title', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhere('reference_no', 'like', "%{$search}%");
                });
            }
        }

        $documents = $query->latest('id')->paginate($perPage)->withQueryString();
        $approvedDocumentIds = $access->approvedDocumentIds($user, $documents->getCollection()->pluck('id')->all());

        $documents->getCollection()->each(function (Document $document) use ($user, $access, $approvedDocumentIds): void {
            $document->setAttribute('can_download', $access->canDownload($user, $document, $approvedDocumentIds));
        });

        return DocumentResource::collection($documents);
    }

    public function show(
        Request $request,
        Document $ddmDocument,
        DocumentAccessService $access,
    ): DocumentResource {
        return $this->respond($ddmDocument, $request->user(), $access);
    }

    public function store(
        StoreDocumentRequest $request,
        DocumentService $documents,
        DocumentAccessService $access,
    ): JsonResponse {
        $document = $documents->create(
            Arr::except($request->validated(), ['file']),
            $request->file('file'),
            $request->user(),
        );

        return $this->respond($document, $request->user(), $access)
            ->response()
            ->setStatusCode(201);
    }

    public function update(
        UpdateDocumentRequest $request,
        Document $ddmDocument,
        DocumentService $documents,
        DocumentAccessService $access,
    ): DocumentResource {
        $document = $documents->update($ddmDocument, $request->validated(), $request->user());

        return $this->respond($document, $request->user(), $access);
    }

    public function storeVersion(
        StoreDocumentVersionRequest $request,
        Document $ddmDocument,
        DocumentService $documents,
    ): JsonResponse {
        $version = $documents->addVersion(
            $ddmDocument,
            $request->file('file'),
            $request->input('change_note'),
            $request->user(),
        );

        return (new DocumentVersionResource($version))
            ->response()
            ->setStatusCode(201);
    }

    public function archive(
        Request $request,
        Document $ddmDocument,
        DocumentService $documents,
        DocumentAccessService $access,
    ): DocumentResource {
        $document = $documents->archive($ddmDocument, $request->user());

        return $this->respond($document, $request->user(), $access);
    }

    public function unarchive(
        Request $request,
        Document $ddmDocument,
        DocumentService $documents,
        DocumentAccessService $access,
    ): DocumentResource {
        $document = $documents->unarchive($ddmDocument, $request->user());

        return $this->respond($document, $request->user(), $access);
    }

    public function destroy(
        DeleteDocumentRequest $request,
        Document $ddmDocument,
        DocumentService $documents,
    ): Response {
        $documents->delete($ddmDocument, $request->validated('reason'), $request->user());

        return response()->noContent();
    }

    public function restore(
        RestoreDocumentRequest $request,
        Document $ddmDeletedDocument,
        DocumentService $documents,
        DocumentAccessService $access,
    ): DocumentResource {
        $document = $documents->restore($ddmDeletedDocument, $request->validated('reason'), $request->user());

        return $this->respond($document, $request->user(), $access);
    }

    private function respond(Document $document, User $user, DocumentAccessService $access): DocumentResource
    {
        $document = $document->load([
            'category',
            'creator',
            'latestVersion.uploader',
            'versions.uploader',
        ]);

        $document->setAttribute('can_download', $access->canDownload($user, $document));

        return new DocumentResource($document);
    }
}
