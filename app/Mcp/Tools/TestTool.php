<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Symfony\Component\Process\Process;

#[Description('Runs a targeted Laravel test command with optional filter and compact output. Use for focused verification.')]
class TestTool extends Tool
{
    public function handle(Request $request): Response
    {
        $filter = trim((string) $request->get('filter', ''));

        $command = ['php', 'artisan', 'test', '--compact'];
        if ($filter !== '') {
            $command[] = '--filter';
            $command[] = $filter;
        }

        $process = new Process($command);
        $process->setWorkingDirectory(base_path());
        $process->setTimeout(180);

        try {
            $process->run();

            $output = trim($process->getOutput().PHP_EOL.$process->getErrorOutput());
            $lines = array_slice(preg_split('/\R/', $output) ?: [], -120);

            $prefix = $process->isSuccessful() ? 'SUCCESS' : 'FAILED';

            return Response::text($prefix.PHP_EOL.implode(PHP_EOL, $lines));
        } catch (\Throwable $exception) {
            return Response::error('Could not run tests: '.$exception->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'filter' => $schema->string()
                ->description('Optional PHPUnit/Laravel test filter (class or method substring).'),
        ];
    }
}
