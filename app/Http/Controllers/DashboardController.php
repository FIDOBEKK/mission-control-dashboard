<?php

namespace App\Http\Controllers;

use App\Services\MissionDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(
        private readonly MissionDataService $missionDataService,
    ) {}

    public function index(Request $request): View
    {
        $modules = [
            ['key' => 'tasks', 'label' => 'Tasks'],
            ['key' => 'calendar', 'label' => 'Calendar'],
            ['key' => 'processes', 'label' => 'Processes'],
            ['key' => 'notes', 'label' => 'Notes'],
            ['key' => 'decisions', 'label' => 'Decisions'],
        ];

        $activeModule = (string) $request->query('module', 'tasks');
        $validKeys = collect($modules)->pluck('key')->all();
        if (! in_array($activeModule, $validKeys, true)) {
            $activeModule = 'tasks';
        }

        $mission = $this->missionDataService->getMissionData(preferCache: false);

        return view('dashboard.index', [
            'initialMission' => $mission,
            'calendarWeek' => $mission['calendarWeek'] ?? [],
            'calendarSummary' => $mission['calendarSummary'] ?? [],
            'modules' => $modules,
            'activeModule' => $activeModule,
        ]);
    }

    public function mission(): JsonResponse
    {
        return response()->json($this->missionDataService->getMissionData(preferCache: false));
    }
}
