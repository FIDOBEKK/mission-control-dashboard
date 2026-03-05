<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\AboutTool;
use App\Mcp\Tools\RoutesTool;
use App\Mcp\Tools\TestTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Mission Control Laravel Dev Server')]
#[Version('1.0.0')]
#[Instructions('Use this server for Laravel project context: app health, route overview and targeted test runs. Keep actions safe and concise.')]
class DevServer extends Server
{
    protected array $tools = [
        AboutTool::class,
        RoutesTool::class,
        TestTool::class,
    ];

    protected array $resources = [
        //
    ];

    protected array $prompts = [
        //
    ];
}
