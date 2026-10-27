<?php

namespace App\Http\Controllers;

use App\Http\Requests\IndexAuditLogRequest;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class AuditLogController extends Controller
{
    public function index(IndexAuditLogRequest $request): AnonymousResourceCollection
    {
        $query = AuditLog::query()->with('user');

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        }

        if ($request->filled('module')) {
            $query->where('module', (string) $request->string('module'));
        }

        if ($request->filled('action')) {
            $query->where('action', (string) $request->string('action'));
        }

        if ($request->filled('auditable_type')) {
            $query->where('auditable_type', (string) $request->string('auditable_type'));
        }

        if ($request->filled('auditable_id')) {
            $query->where('auditable_id', $request->integer('auditable_id'));
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->date('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->date('to'));
        }

        $perPage = $request->integer('per_page', (int) config('api.pagination.default', 15));

        return AuditLogResource::collection(
            $query->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate($perPage)
                ->withQueryString(),
        );
    }

    public function show(AuditLog $auditLog): AuditLogResource
    {
        $auditLog->load('user');

        return new AuditLogResource($auditLog);
    }
}
