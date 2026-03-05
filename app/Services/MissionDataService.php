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
        $calendarModule = $this->buildCalendarModuleData($calendarItems, $scoredActionable, $columns);

        return [
            'statusItems' => $statusItems,
            'columns' => $columns,
            'week' => $week,
            'weekItems' => $this->buildCondensedWeekItems($calendarItems->merge($scoredActionable)),
            'nowItems' => $this->buildNowItems($scoredActionable),
            'waitingItems' => $this->buildWaitingItems($scoredActionable),
            'weekUnscheduled' => $weekUnscheduled->take(20)->values()->all(),
            'liveProcesses' => $liveProcesses,
            'calendarWeek' => $calendarModule['calendarWeek'],
            'calendarSummary' => $calendarModule['calendarSummary'],
            'fetchedAt' => now()->toIso8601String(),
            'sources' => array_merge($sources, $calendarModule['sourceDiagnostics']),
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
     * @param  Collection<int, array<string, mixed>>  $calendarItems
     * @param  Collection<int, array<string, mixed>>  $scoredActionable
     * @param  array<string, array<int, string>>  $columns
     * @return array{calendarWeek: array<int, array<string, mixed>>, calendarSummary: array<string, mixed>, sourceDiagnostics: array<int, array<string, mixed>>}
     */
    private function buildCalendarModuleData(Collection $calendarItems, Collection $scoredActionable, array $columns): array
    {
        $weekStart = now()->startOfWeek(Carbon::MONDAY);
        $weekEnd = $weekStart->copy()->addDays(6)->endOfDay();

        $timedEvents = $calendarItems
            ->map(function (array $item): ?array {
                $date = $item['date'] ?? null;
                if (! is_string($date) || $date === '') {
                    return null;
                }

                $time = is_string($item['time'] ?? null) ? $item['time'] : null;
                $startAt = $time ? "{$date} {$time}:00" : "{$date} 09:00:00";
                $endAt = $time ? Carbon::parse($startAt)->addMinutes(30)->toDateTimeString() : Carbon::parse($startAt)->addHour()->toDateTimeString();

                return [
                    'title' => (string) ($item['title'] ?? 'Task'),
                    'source' => (string) ($item['source'] ?? 'Mission data'),
                    'timeRange' => $time ? "{$time} - ".Carbon::parse($endAt)->format('H:i') : 'Planned',
                    'location' => null,
                    'date' => $date,
                    'startAt' => Carbon::parse($startAt)->toIso8601String(),
                    'endAt' => Carbon::parse($endAt)->toIso8601String(),
                    'sortAt' => Carbon::parse($startAt)->getTimestamp(),
                ];
            })
            ->filter();

        $datedActionableEvents = $scoredActionable
            ->filter(fn (array $item): bool => is_string($item['date'] ?? null) && ($item['date'] !== ''))
            ->map(function (array $item): array {
                $date = (string) $item['date'];

                return [
                    'title' => (string) ($item['title'] ?? 'Task'),
                    'source' => (string) ($item['source'] ?? 'Mission data'),
                    'timeRange' => 'Task',
                    'location' => null,
                    'date' => $date,
                    'startAt' => Carbon::parse($date.' 13:00:00')->toIso8601String(),
                    'endAt' => Carbon::parse($date.' 13:30:00')->toIso8601String(),
                    'sortAt' => Carbon::parse($date.' 13:00:00')->getTimestamp(),
                ];
            });

        $events = $timedEvents
            ->merge($datedActionableEvents)
            ->filter(function (array $event) use ($weekStart, $weekEnd): bool {
                try {
                    $date = Carbon::parse((string) ($event['date'] ?? ''));

                    return $date->betweenIncluded($weekStart, $weekEnd);
                } catch (Throwable) {
                    return false;
                }
            })
            ->sortBy('sortAt')
            ->values();

        $calendarWeek = collect(range(0, 6))
            ->map(function (int $offset) use ($weekStart, $events): array {
                $day = $weekStart->copy()->addDays($offset);
                $dateKey = $day->toDateString();

                $dayEvents = $events
                    ->filter(fn (array $event): bool => ($event['date'] ?? null) === $dateKey)
                    ->values();

                $conflictIndexes = [];
                $conflicts = 0;
                $lastEndAt = null;

                foreach ($dayEvents as $index => $event) {
                    $startAt = Arr::get($event, 'startAt');
                    $endAt = Arr::get($event, 'endAt');

                    if (! is_string($startAt) || ! is_string($endAt)) {
                        continue;
                    }

                    try {
                        $start = Carbon::parse($startAt);
                        $end = Carbon::parse($endAt);
                    } catch (Throwable) {
                        continue;
                    }

                    if ($lastEndAt && $start->lt($lastEndAt)) {
                        $conflictIndexes[$index] = true;
                        if ($index > 0) {
                            $conflictIndexes[$index - 1] = true;
                        }
                        $conflicts++;
                    }

                    if (! $lastEndAt || $end->gt($lastEndAt)) {
                        $lastEndAt = $end;
                    }
                }

                return [
                    'date' => $dateKey,
                    'label' => $day->format('l'),
                    'dateLabel' => $day->format('d M'),
                    'events' => $dayEvents
                        ->map(function (array $event, int $index) use ($conflictIndexes): array {
                            return [
                                'title' => (string) ($event['title'] ?? 'Untitled task'),
                                'source' => (string) ($event['source'] ?? 'Mission data'),
                                'timeRange' => (string) ($event['timeRange'] ?? 'Task'),
                                'location' => $event['location'] ?? null,
                                'isConflict' => isset($conflictIndexes[$index]),
                            ];
                        })
                        ->all(),
                    'conflicts' => $conflicts,
                ];
            })
            ->all();

        $unscheduled = collect(['planned', 'backlog', 'active', 'review'])
            ->flatMap(function (string $column) use ($columns): Collection {
                return collect($columns[$column] ?? [])->map(fn (string $title): array => [
                    'title' => $title,
                    'type' => $column,
                    'source' => 'Mission data',
                ]);
            })
            ->unique('title')
            ->take(20)
            ->values()
            ->all();

        $totalEvents = collect($calendarWeek)->sum(fn (array $day): int => count($day['events'] ?? []));
        $daysWithEvents = collect($calendarWeek)->filter(fn (array $day): bool => ! empty($day['events']))->count();
        $conflictsCount = collect($calendarWeek)->sum(fn (array $day): int => (int) ($day['conflicts'] ?? 0));

        $diagnostics = [
            [
                'name' => 'assistant week schedule',
                'ok' => true,
                'message' => 'Built from cron jobs, GitHub items and active mission tasks',
            ],
        ];

        return [
            'calendarWeek' => $calendarWeek,
            'calendarSummary' => [
                'totalEvents' => $totalEvents,
                'daysWithEvents' => $daysWithEvents,
                'conflictsCount' => $conflictsCount,
                'unscheduled' => $unscheduled,
                'diagnostics' => $diagnostics,
            ],
            'sourceDiagnostics' => $diagnostics,
        ];
    }

    /**
     * @return array{name: string, ok: bool, message: string, events: array<int, array<string, mixed>>}
     */
    private function fetchMicrosoftCalendarEvents(Carbon $start, Carbon $end): array
    {
        $service = 'microsoft_graph_token_fido_readonly';
        $account = 'fidobot-bekk';

        $tokenResult = $this->runCommand([
            'security',
            'find-generic-password',
            '-a',
            $account,
            '-s',
            $service,
            '-w',
        ], timeout: 5);

        if (! $tokenResult['ok']) {
            return [
                'name' => 'calendar microsoft',
                'ok' => false,
                'message' => 'No Microsoft token available in keychain',
                'events' => [],
            ];
        }

        $tokenPayload = json_decode($tokenResult['output'], true);
        $accessToken = is_array($tokenPayload) ? Arr::get($tokenPayload, 'access_token') : null;

        if (! is_string($accessToken) || $accessToken === '') {
            return [
                'name' => 'calendar microsoft',
                'ok' => false,
                'message' => 'Microsoft token payload missing access token',
                'events' => [],
            ];
        }

        $response = $this->runCommand([
            'curl',
            '-sS',
            '-G',
            'https://graph.microsoft.com/v1.0/me/calendarView',
            '-H',
            "Authorization: Bearer {$accessToken}",
            '-H',
            'Accept: application/json',
            '-H',
            'Prefer: outlook.timezone="Europe/Oslo"',
            '--data-urlencode',
            'startDateTime='.$start->format('Y-m-d\TH:i:s'),
            '--data-urlencode',
            'endDateTime='.$end->format('Y-m-d\TH:i:s'),
            '--data-urlencode',
            '$select=subject,start,end,location,isCancelled',
            '--data-urlencode',
            '$orderby=start/dateTime',
            '--data-urlencode',
            '$top=100',
        ], timeout: 10);

        if (! $response['ok']) {
            return [
                'name' => 'calendar microsoft',
                'ok' => false,
                'message' => Str::limit($response['message'] ?: 'Microsoft calendar request failed', 120),
                'events' => [],
            ];
        }

        $decoded = json_decode($response['output'], true);
        if (! is_array($decoded)) {
            return [
                'name' => 'calendar microsoft',
                'ok' => false,
                'message' => 'Microsoft calendar response was not valid JSON',
                'events' => [],
            ];
        }

        $events = collect(Arr::get($decoded, 'value', []))
            ->filter(fn (mixed $event): bool => is_array($event) && Arr::get($event, 'isCancelled') !== true)
            ->map(fn (array $event): ?array => $this->normalizeCalendarEvent(
                source: 'Microsoft',
                title: (string) Arr::get($event, 'subject', 'Untitled event'),
                startAt: Arr::get($event, 'start.dateTime'),
                endAt: Arr::get($event, 'end.dateTime'),
                location: Arr::get($event, 'location.displayName'),
            ))
            ->filter()
            ->values()
            ->all();

        return [
            'name' => 'calendar microsoft',
            'ok' => true,
            'message' => sprintf('%d events in next 7 days', count($events)),
            'events' => $events,
        ];
    }

    /**
     * @return array{name: string, ok: bool, message: string, events: array<int, array<string, mixed>>}
     */
    private function fetchIcsCalendarEvents(string $source, string $keychainService, Carbon $start, Carbon $end): array
    {
        $account = 'fidobot-bekk';

        $urlResult = $this->runCommand([
            'security',
            'find-generic-password',
            '-a',
            $account,
            '-s',
            $keychainService,
            '-w',
        ], timeout: 5);

        if (! $urlResult['ok']) {
            return [
                'name' => 'calendar '.Str::lower($source),
                'ok' => false,
                'message' => 'Calendar feed URL unavailable in keychain',
                'events' => [],
            ];
        }

        $feedUrl = trim($urlResult['output']);
        if ($feedUrl === '') {
            return [
                'name' => 'calendar '.Str::lower($source),
                'ok' => false,
                'message' => 'Calendar feed URL was empty',
                'events' => [],
            ];
        }

        if (Str::startsWith($feedUrl, 'webcal://')) {
            $feedUrl = 'https://'.Str::after($feedUrl, 'webcal://');
        }

        $feedResult = $this->runCommand([
            'curl',
            '-sS',
            '-L',
            $feedUrl,
        ], timeout: 10);

        if (! $feedResult['ok']) {
            return [
                'name' => 'calendar '.Str::lower($source),
                'ok' => false,
                'message' => Str::limit($feedResult['message'] ?: 'Unable to fetch ICS feed', 120),
                'events' => [],
            ];
        }

        $events = $this->parseIcsCalendar($feedResult['output'], $source, $start, $end);

        return [
            'name' => 'calendar '.Str::lower($source),
            'ok' => true,
            'message' => sprintf('%d events in next 7 days', count($events)),
            'events' => $events,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseIcsCalendar(string $icsPayload, string $source, Carbon $start, Carbon $end): array
    {
        $lines = preg_split('/\R/', $icsPayload) ?: [];
        $unfolded = [];

        foreach ($lines as $line) {
            if (($line !== '') && ($line[0] === ' ' || $line[0] === "\t") && ! empty($unfolded)) {
                $unfolded[count($unfolded) - 1] .= substr($line, 1);
                continue;
            }

            $unfolded[] = $line;
        }

        $events = [];
        $inEvent = false;
        $eventFields = [];

        foreach ($unfolded as $line) {
            if ($line === 'BEGIN:VEVENT') {
                $inEvent = true;
                $eventFields = [];

                continue;
            }

            if ($line === 'END:VEVENT') {
                $inEvent = false;

                $startField = $eventFields['DTSTART'][0] ?? null;
                $endField = $eventFields['DTEND'][0] ?? $startField;
                $title = (string) (($eventFields['SUMMARY'][0]['value'] ?? null) ?: 'Untitled event');
                $location = $eventFields['LOCATION'][0]['value'] ?? null;

                $normalized = $this->normalizeCalendarEvent(
                    source: $source,
                    title: $title,
                    startAt: $startField['value'] ?? null,
                    endAt: $endField['value'] ?? null,
                    location: is_string($location) && $location !== '' ? $location : null,
                    startMeta: $startField['meta'] ?? [],
                    endMeta: $endField['meta'] ?? [],
                );

                if ($normalized !== null) {
                    $eventDate = Carbon::parse($normalized['startAt']);
                    if ($eventDate->betweenIncluded($start, $end)) {
                        $events[] = $normalized;
                    }
                }

                continue;
            }

            if (! $inEvent || ! str_contains($line, ':')) {
                continue;
            }

            [$rawKey, $value] = explode(':', $line, 2);
            [$name, $meta] = $this->parseIcsKey($rawKey);
            $eventFields[$name][] = [
                'value' => trim($value),
                'meta' => $meta,
            ];
        }

        return collect($events)
            ->sortBy('sortAt')
            ->values()
            ->all();
    }

    /**
     * @return array{0: string, 1: array<string, string>}
     */
    private function parseIcsKey(string $rawKey): array
    {
        $parts = explode(';', $rawKey);
        $name = strtoupper(array_shift($parts) ?? $rawKey);
        $meta = [];

        foreach ($parts as $part) {
            if (! str_contains($part, '=')) {
                continue;
            }

            [$metaKey, $metaValue] = explode('=', $part, 2);
            $meta[strtoupper($metaKey)] = $metaValue;
        }

        return [$name, $meta];
    }

    /**
     * @param  array<string, string>  $startMeta
     * @param  array<string, string>  $endMeta
     * @return array<string, mixed>|null
     */
    private function normalizeCalendarEvent(
        string $source,
        string $title,
        mixed $startAt,
        mixed $endAt,
        ?string $location = null,
        array $startMeta = [],
        array $endMeta = [],
    ): ?array {
        if (! is_string($startAt) || $startAt === '') {
            return null;
        }

        try {
            $start = $this->parseCalendarDateTime($startAt, $startMeta);
            $end = is_string($endAt) && $endAt !== ''
                ? $this->parseCalendarDateTime($endAt, $endMeta)
                : $start->copy()->addHour();
        } catch (Throwable) {
            return null;
        }

        if ($end->lessThanOrEqualTo($start)) {
            $end = $start->copy()->addMinutes(30);
        }

        $isAllDay = ($startMeta['VALUE'] ?? null) === 'DATE';
        $timeRange = $isAllDay
            ? 'Hele dagen'
            : $start->format('H:i').' - '.$end->format('H:i');

        return [
            'source' => $source,
            'title' => $title,
            'location' => $location,
            'startAt' => $start->toIso8601String(),
            'endAt' => $end->toIso8601String(),
            'date' => $start->toDateString(),
            'timeRange' => $timeRange,
            'sortAt' => $start->getTimestamp(),
        ];
    }

    /**
     * @param  array<string, string>  $meta
     */
    private function parseCalendarDateTime(string $value, array $meta = []): Carbon
    {
        $timezone = $meta['TZID'] ?? 'Europe/Oslo';

        if (($meta['VALUE'] ?? null) === 'DATE' && preg_match('/^\d{8}$/', $value) === 1) {
            return Carbon::createFromFormat('Ymd', $value, $timezone)->startOfDay();
        }

        if (preg_match('/^\d{8}T\d{6}Z$/', $value) === 1) {
            return Carbon::createFromFormat('Ymd\THis\Z', $value, 'UTC')->setTimezone($timezone);
        }

        if (preg_match('/^\d{8}T\d{6}$/', $value) === 1) {
            return Carbon::createFromFormat('Ymd\THis', $value, $timezone);
        }

        return Carbon::parse($value, $timezone);
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
