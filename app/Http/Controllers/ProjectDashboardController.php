<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProjectDashboardRequest;
use App\Services\ProjectDashboardService;
use App\Services\ProjectFilter;
use Illuminate\Http\JsonResponse;

final class ProjectDashboardController extends Controller
{
    public function __invoke(
        ProjectDashboardRequest $request,
        ProjectDashboardService $dashboard,
    ): JsonResponse {
        return response()->json(
            $dashboard->build(
                $request->user(),
                ProjectFilter::fromArray($request->validated()),
            ),
        );
    }
}
