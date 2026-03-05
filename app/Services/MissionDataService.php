<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

class MissionDataService
{
    /**
     * @return array<string, mixed>
     */
    public function getMissionData(): array
    {
        $sources = [];
        $columns = [
            'planned' => [],
            'backlog' => [],
            'active' => [],
            'review' => [],
            'done' => [],
        ];

        $statusItems = [];
        $calendarItems = collect();
        $weekUnscheduled = collect();
        $actionableItems = collect();
        $liveProcesses = [];

        $cronData = $this->runCommand(['openclaw', 'cron', 'list', '--json'], timeout: 6);
        $sources[] = $this->sourceStatus('openclaw cron', $cronData);

        if ($cronData['ok']) {
            $decoded = json_decode($cronData['output'], true);
            $jobs = collect(is_array($decoded) ? $decoded : [])
                ->filter(fn (mixed $item): bool => is_array($item))
                ->values();

            $columns['planned'] = $jobs
                ->map(function (array $job): string {
                    $name = Arr::get($job, 'name', 'Unnamed cron job');
                    $schedule = Arr::get($job, 'schedule', Arr::get($job, 'cron', 'unknown schedule'));

                    return trim("{$name} • {$schedule}");
                })
                ->take(8)
                ->all();

            $calendarItems = $calendarItems->merge(
                $jobs
                    ->map(function (array $job): ?array {
                        $nextRunAt = Arr::get($job, 'nextRunAt') ?? Arr::get($job, 'next_run_at');
                        if (! is_string($nextRunAt)) {
                            return null;
                        }

                        $date = Carbon::parse($nextRunAt);

                        return [
                            'title' => Arr::get($job, 'name', 'Cron task'),
                            'type' => 'planned',
                            'time' => $date->format('H:i'),
                            'date' => $date->toDateString(),
                            'source' => 'openclaw',
                            'updatedAt' => $date->toIso8601String(),
                        ];
                    })
                    ->filter()
                    ->values()
            );

            $statusItems[] = [
                'name' => 'Cron jobs',
                'status' => sprintf('%d tracked', $jobs->count()),
                'tone' => 'text-sky-300',
            ];
        }

        $issuesData = $this->runCommand([
            'gh',
            'issue',
            'list',
            '--repo',
            'OnePagerHub/frame-generator',
            '--state',
            'open',
            '--limit',
            '25',
            '--json',
            'number,title,updatedAt,url',
        ], timeout: 8);
        $sources[] = $this->sourceStatus('gh issues', $issuesData);

        if ($issuesData['ok']) {
            $issues = collect(json_decode($issuesData['output'], true) ?: []);
            $columns['backlog'] = $issues
                ->map(fn (array $issue): string => sprintf('#%s %s', $issue['number'] ?? '?', $issue['title'] ?? 'Untitled issue'))
                ->take(12)
                ->all();

            $weekUnscheduled = $weekUnscheduled->merge(
                $issues
                    ->map(fn (array $issue): array => [
                        'title' => sprintf('#%s %s', $issue['number'] ?? '?', $issue['title'] ?? 'Untitled issue'),
                        'type' => 'backlog',
                        'source' => 'GitHub issues',
                    ])
            );

            $actionableItems = $actionableItems->merge(
                $issues->map(function (array $issue): array {
                    $title = sprintf('#%s %s', $issue['number'] ?? '?', $issue['title'] ?? 'Untitled issue');
                    $updatedAt = is_string($issue['updatedAt'] ?? null) ? $issue['updatedAt'] : null;
                    $date = $updatedAt ? Carbon::parse($updatedAt)->toDateString() : null;

                    return [
                        'title' => $title,
                        'type' => 'backlog',
                        'source' => 'GitHub issues',
                        'date' => $date,
                        'updatedAt' => $updatedAt,
                    ];
                })
            );

            $statusItems[] = [
                'name' => 'GitHub issues',
                'status' => sprintf('%d open', $issues->count()),
                'tone' => 'text-violet-300',
            ];
        }

        $prsData = $this->runCommand([
            'gh',
            'pr',
            'list',
            '--repo',
            'OnePagerHub/frame-generator',
            '--state',
            'open',
            '--limit',
            '20',
            '--json',
            'number,title,updatedAt,reviewDecision,url',
        ], timeout: 8);
        $sources[] = $this->sourceStatus('gh prs', $prsData);

        if ($prsData['ok']) {
            $prs = collect(json_decode($prsData['output'], true) ?: []);

            $columns['active'] = $prs
                ->map(fn (array $pr): string => sprintf('PR #%s %s', $pr['number'] ?? '?', $pr['title'] ?? 'Untitled PR'))
                ->take(10)
                ->all();

            $columns['review'] = $prs
                ->filter(function (array $pr): bool {
                    $decision = Str::lower((string) ($pr['reviewDecision'] ?? ''));

                    return $decision === 'review_required' || $decision === '';
                })
                ->map(fn (array $pr): string => sprintf('PR #%s %s', $pr['number'] ?? '?', $pr['title'] ?? 'Untitled PR'))
                ->take(10)
                ->all();

            $calendarItems = $calendarItems->merge(
                $prs->map(function (array $pr): ?array {
                    if (empty($pr['updatedAt'])) {
                        return null;
                    }

                    $date = Carbon::parse((string) $pr['updatedAt']);

                    return [
                        'title' => sprintf('PR #%s %s', $pr['number'] ?? '?', $pr['title'] ?? 'Untitled PR'),
                        'type' => 'active',
                        'time' => $date->format('H:i'),
                        'date' => $date->toDateString(),
                        'source' => 'GitHub PRs',
                        'updatedAt' => $date->toIso8601String(),
                    ];
                })->filter()->values()
            );

            $actionableItems = $actionableItems->merge(
                $prs->map(function (array $pr): array {
                    $decision = Str::lower((string) ($pr['reviewDecision'] ?? ''));
                    $needsReview = $decision === 'review_required' || $decision === '';
                    $title = sprintf('PR #%s %s', $pr['number'] ?? '?', $pr['title'] ?? 'Untitled PR');
                    $updatedAt = is_string($pr['updatedAt'] ?? null) ? $pr['updatedAt'] : null;
                    $date = $updatedAt ? Carbon::parse($updatedAt)->toDateString() : null;

                    return [
                        'title' => $title,
                        'type' => $needsReview ? 'review' : 'active',
                        'source' => 'GitHub PRs',
                        'date' => $date,
                        'updatedAt' => $updatedAt,
                        'waiting' => $needsReview,
                    ];
                })
            );

            $statusItems[] = [
                'name' => 'Pull requests',
                'status' => sprintf('%d open', $prs->count()),
                'tone' => 'text-emerald-300',
            ];
        }

        $processData = $this->runCommand(['ps', '-Ao', 'pid,pcpu,pmem,comm,args'], timeout: 4);
        $sources[] = $this->sourceStatus('ps snapshot', $processData);

        if ($processData['ok']) {
            $lines = collect(preg_split('/\R/', $processData['output'] ?? ''))
                ->skip(1)
                ->filter()
                ->values();

            $keywords = ['php', 'artisan', 'node', 'vite', 'gh', 'openclaw', 'git'];

            $liveProcesses = $lines
                ->filter(function (string $line) use ($keywords): bool {
                    $haystack = Str::lower($line);

                    return collect($keywords)->contains(fn (string $keyword): bool => Str::contains($haystack, $keyword));
                })
                ->take(10)
                ->map(function (string $line): array {
                    $clean = preg_replace('/\s+/', ' ', trim($line)) ?? trim($line);
                    $parts = explode(' ', $clean, 5);

                    return [
                        'name' => $parts[3] ?? 'process',
                        'detail' => $parts[4] ?? $clean,
                        'state' => sprintf('CPU %s%% • MEM %s%%', $parts[1] ?? '?', $parts[2] ?? '?'),
                    ];
                })
                ->all();

            $statusItems[] = [
                'name' => 'Live processes',
                'status' => sprintf('%d relevant', count($liveProcesses)),
                'tone' => 'text-amber-300',
            ];
        }

        $gitRepos = collect([
            base_path(),
            '/Users/andersiglebekk/Documents/OnePagerHub/frame-generator',
            '/Users/andersiglebekk/Documents/frame-generator',
        ])->filter(fn (string $path): bool => is_dir($path.'/.git'))->unique()->values();

        $gitSummaries = collect();

        foreach ($gitRepos as $repoPath) {
            $repoName = basename($repoPath);
            $gitData = $this->runCommand([
                'git',
                '-C',
                $repoPath,
                'log',
                '-n',
                '5',
                '--pretty=format:%h|%s|%cr',
            ], timeout: 4);

            $sources[] = $this->sourceStatus("git log {$repoName}", $gitData);

            if ($gitData['ok']) {
                $entries = collect(preg_split('/\R/', $gitData['output'] ?? ''))
                    ->filter()
                    ->map(fn (string $line): string => "{$repoName}: {$line}");

                $gitSummaries = $gitSummaries->merge($entries);
            }
        }

        $columns['done'] = $gitSummaries->take(12)->all();

        $scoredActionable = $actionableItems
            ->map(function (array $item): array {
                $item['score'] = $this->scoreItem($item);

                return $item;
            })
            ->sortByDesc('score')
            ->values();

        $week = $this->buildWeekView($calendarItems);

        return [
            'statusItems' => $statusItems,
            'columns' => $columns,
            'week' => $week,
            'weekItems' => $this->buildCondensedWeekItems($calendarItems->merge($scoredActionable)),
            'nowItems' => $this->buildNowItems($scoredActionable),
            'waitingItems' => $this->buildWaitingItems($scoredActionable),
            'weekUnscheduled' => $weekUnscheduled->take(20)->values()->all(),
            'liveProcesses' => $liveProcesses,
            'fetchedAt' => now()->toIso8601String(),
            'sources' => $sources,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function buildWeekView(Collection $items): array
    {
        $startOfWeek = now()->startOfWeek(Carbon::MONDAY);

        return collect(range(0, 6))
            ->map(function (int $offset) use ($startOfWeek, $items): array {
                $day = $startOfWeek->copy()->addDays($offset);
                $dateKey = $day->toDateString();

                $dayItems = $items
                    ->filter(fn (array $item): bool => ($item['date'] ?? null) === $dateKey)
                    ->map(function (array $item): array {
                        return [
                            'title' => (string) ($item['title'] ?? 'Untitled'),
                            'type' => (string) ($item['type'] ?? 'planned'),
                            'time' => $item['time'] ?? null,
                            'source' => $item['source'] ?? null,
                        ];
                    })
                    ->values()
                    ->all();

                return [
                    'dayKey' => Str::lower($day->format('D')),
                    'label' => $day->format('l'),
                    'date' => $day->format('d M'),
                    'items' => $dayItems,
                ];
            })
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function buildCondensedWeekItems(Collection $items): array
    {
        $startOfWeek = now()->startOfWeek(Carbon::MONDAY);

        return collect(range(0, 6))
            ->map(function (int $offset) use ($startOfWeek, $items): array {
                $day = $startOfWeek->copy()->addDays($offset);
                $dateKey = $day->toDateString();

                $dayItems = $items
                    ->filter(fn (array $item): bool => ($item['date'] ?? null) === $dateKey)
                    ->sortByDesc(fn (array $item): int => (int) ($item['score'] ?? $this->scoreItem($item)))
                    ->map(function (array $item): string {
                        $title = (string) ($item['title'] ?? 'Untitled');
                        $time = isset($item['time']) && is_string($item['time']) ? "{$item['time']} " : '';

                        return trim($time.$title);
                    })
                    ->take(3)
                    ->values()
                    ->all();

                return [
                    'dayKey' => Str::lower($day->format('D')),
                    'label' => $day->shortEnglishDayOfWeek,
                    'date' => $day->format('d M'),
                    'items' => $dayItems,
                ];
            })
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function buildNowItems(Collection $items): array
    {
        return $items
            ->filter(fn (array $item): bool => in_array(($item['type'] ?? ''), ['active', 'review'], true))
            ->take(6)
            ->map(function (array $item): array {
                return [
                    'title' => (string) ($item['title'] ?? 'Untitled'),
                    'type' => (string) ($item['type'] ?? 'active'),
                    'source' => (string) ($item['source'] ?? 'unknown'),
                    'score' => (int) ($item['score'] ?? 0),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function buildWaitingItems(Collection $items): array
    {
        return $items
            ->filter(function (array $item): bool {
                if (($item['type'] ?? '') === 'review') {
                    return true;
                }

                if (($item['waiting'] ?? false) === true) {
                    return true;
                }

                $title = Str::lower((string) ($item['title'] ?? ''));

                return Str::contains($title, ['wait', 'waiting', 'venter', 'approval', 'approve', 'godkjenn']);
            })
            ->take(8)
            ->map(function (array $item): array {
                return [
                    'title' => (string) ($item['title'] ?? 'Untitled'),
                    'type' => (string) ($item['type'] ?? 'review'),
                    'source' => (string) ($item['source'] ?? 'unknown'),
                    'score' => (int) ($item['score'] ?? 0),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function scoreItem(array $item): int
    {
        $type = (string) ($item['type'] ?? 'backlog');
        $title = Str::lower((string) ($item['title'] ?? ''));

        $typeWeight = [
            'review' => 50,
            'active' => 40,
            'planned' => 22,
            'backlog' => 18,
            'done' => 8,
        ];

        $score = $typeWeight[$type] ?? 10;

        $keywordWeights = [
            'urgent' => 35,
            'haster' => 35,
            'blocker' => 32,
            'blocked' => 28,
            'blokkert' => 28,
            'review' => 24,
            'gjennomgang' => 24,
            'approval' => 22,
            'approve' => 22,
            'godkjenning' => 22,
            'today' => 18,
            'i dag' => 18,
        ];

        foreach ($keywordWeights as $keyword => $weight) {
            if (Str::contains($title, $keyword)) {
                $score += $weight;
            }
        }

        if (($item['waiting'] ?? false) === true) {
            $score += 14;
        }

        $dateCandidate = $item['updatedAt'] ?? $item['date'] ?? null;
        if (is_string($dateCandidate) && $dateCandidate !== '') {
            try {
                $hours = abs(now()->diffInHours(Carbon::parse($dateCandidate), false));

                if ($hours <= 24) {
                    $score += 24;
                } elseif ($hours <= 72) {
                    $score += 14;
                } elseif ($hours <= 168) {
                    $score += 8;
                }
            } catch (Throwable) {
                // Ignore invalid recency hints.
            }
        }

        return $score;
    }

    /**
     * @param  array<int, string>  $command
     * @return array{ok: bool, output: string, message: string, exitCode: int|null}
     */
    private function runCommand(array $command, ?string $cwd = null, int $timeout = 8): array
    {
        try {
            $process = new Process($command, $cwd);
            $process->setTimeout($timeout);
            $process->run();

            $output = trim($process->getOutput());
            $error = trim($process->getErrorOutput());

            if (! $process->isSuccessful()) {
                return [
                    'ok' => false,
                    'output' => $output,
                    'message' => $error !== '' ? $error : 'Command failed',
                    'exitCode' => $process->getExitCode(),
                ];
            }

            return [
                'ok' => true,
                'output' => $output,
                'message' => 'ok',
                'exitCode' => $process->getExitCode(),
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'output' => '',
                'message' => $exception->getMessage(),
                'exitCode' => null,
            ];
        }
    }

    /**
     * @param  array{ok: bool, message: string, output: string}  $result
     * @return array{name: string, ok: bool, message: string}
     */
    private function sourceStatus(string $name, array $result): array
    {
        $message = $result['ok']
            ? 'ok'
            : Str::limit($result['message'] !== '' ? $result['message'] : 'failed', 120);

        return [
            'name' => $name,
            'ok' => $result['ok'],
            'message' => $message,
        ];
    }
}
