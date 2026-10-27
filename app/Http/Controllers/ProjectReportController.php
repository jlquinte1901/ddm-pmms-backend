<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExportProjectReportRequest;
use App\Http\Requests\ProjectReportRequest;
use App\Services\ProjectFilter;
use App\Services\ProjectReportService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ProjectReportController extends Controller
{
    public function index(
        ProjectReportRequest $request,
        ProjectReportService $reports,
    ): JsonResponse {
        return response()->json(
            $reports->summary(
                $request->user(),
                ProjectFilter::fromArray($request->validated()),
                $request->string('group_by', 'status')->toString(),
            ),
        );
    }

    public function export(
        ExportProjectReportRequest $request,
        ProjectReportService $reports,
    ): StreamedResponse {
        return $reports->export(
            $request->user(),
            ProjectFilter::fromArray($request->validated()),
        );
    }
}
