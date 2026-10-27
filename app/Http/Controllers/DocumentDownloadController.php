<?php

namespace App\Http\Controllers;

use App\Exceptions\DownloadApprovalRequiredException;
use App\Models\Document;
use App\Services\DocumentAccessService;
use App\Services\DocumentDownloadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DocumentDownloadController extends Controller
{
    public function download(
        Request $request,
        Document $ddmDocument,
        DocumentAccessService $access,
        DocumentDownloadService $downloads,
    ): StreamedResponse {
        $user = $request->user();

        if (! $access->canDownload($user, $ddmDocument)) {
            $downloads->recordDeniedAttempt($ddmDocument, $user);

            throw new DownloadApprovalRequiredException;
        }

        $version = $downloads->recordAndResolveVersion($ddmDocument, $user);

        return Storage::disk($version->storage_disk)->download(
            $version->storage_path,
            $version->original_name,
            [
                'Content-Type' => $version->mime_type,
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'no-store, private',
            ],
        );
    }
}
