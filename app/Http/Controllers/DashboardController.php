<?php

namespace App\Http\Controllers;

use App\Services\MissionDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(
        private readonly MissionDataService $missionDataService,
    ) {}

    public function index(): View
    {
        return view('dashboard.index', [
            'initialMission' => $this->missionDataService->getMissionData(),
        ]);
    }

    public function mission(): JsonResponse
    {
        return response()->json($this->missionDataService->getMissionData());
    }
}
