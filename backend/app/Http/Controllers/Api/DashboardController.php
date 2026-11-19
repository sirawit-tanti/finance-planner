<?php

namespace App\Http\Controllers\Api;

use App\Application\Dashboard\DashboardService;
use App\Http\Controllers\Controller;
use App\Support\Day;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function show(DashboardService $service): JsonResponse
    {
        return response()->json(['data' => $service->build(Day::today())]);
    }
}