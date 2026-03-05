<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\IsReadOnly;
use Laravel\Mcp\Server\Tool;
use Symfony\Component\Process\Process;

#[Description('Lists Laravel routes (filtered by optional keyword).')]
#[IsReadOnly]
class RoutesTool extends Tool
{
    public function handle(Request $request): Response
    {
        $filter = trim((string) $request->get('filter', ''));

        $process = new Process(['php', 'artisan', 'route:list']);
        $process->setWorkingDirectory(base_path());
        $process->setTimeout(20);

        try {
            $process->mustRun();

            $output = trim($process->getOutput());
            $lines = preg_split('/\R/', $output) ?: [];

            if ($filter !== '') {
                $needle = mb_strtolower($filter);
                $lines = array_values(array_filter($lines, static fn (string $line): bool => mb_strpos(mb_strtolower($line), $needle) !== false
                ));
            }

            $lines = array_slice($lines, 0, 120);

            return Response::text(implode(PHP_EOL, $lines));
        } catch (\Throwable $exception) {
            return Response::error('Could not run `php artisan route:list`: '.$exception->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'filter' => $schema->string()
                ->description('Optional case-insensitive text filter for route output.'),
        ];
    }
}
