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

        return view('dashboard.index', [
            'initialMission' => $this->missionDataService->getMissionData(),
            'modules' => $modules,
            'activeModule' => $activeModule,
        ]);
    }

    public function mission(): JsonResponse
    {
        return response()->json($this->missionDataService->getMissionData());
    }
}
