<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\IsReadOnly;
use Laravel\Mcp\Server\Tool;
use Symfony\Component\Process\Process;

#[Description('Returns a concise Laravel project health summary (php version, app env, app url, and artisan about excerpt).')]
#[IsReadOnly]
class AboutTool extends Tool
{
    public function handle(Request $request): Response
    {
        $process = new Process(['php', 'artisan', 'about']);
        $process->setWorkingDirectory(base_path());
        $process->setTimeout(20);

        try {
            $process->mustRun();

            $output = trim($process->getOutput());
            $lines = array_slice(preg_split('/\R/', $output) ?: [], 0, 40);

            return Response::text(implode(PHP_EOL, $lines));
        } catch (\Throwable $exception) {
            return Response::error('Could not run `php artisan about`: '.$exception->getMessage());
        }
    }
}
