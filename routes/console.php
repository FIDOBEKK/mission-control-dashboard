<?php

use App\Services\MissionDataService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('mission:cache-refresh', function (MissionDataService $missionDataService) {
    $payload = $missionDataService->getMissionData(preferCache: false);
    $payload['cacheRefreshedAt'] = now()->toIso8601String();

    $cachePath = storage_path('app/mission-cache.json');
    if (! is_dir(dirname($cachePath))) {
        mkdir(dirname($cachePath), 0755, true);
    }

    file_put_contents($cachePath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $this->info('Mission cache refreshed: '.$cachePath);
})->purpose('Refresh mission dashboard cache from host-level sources');
