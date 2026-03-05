<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Mission Control</title>
        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @endif
    </head>
    <body class="min-h-screen bg-zinc-950 text-zinc-100 antialiased">
        <div class="mx-auto flex min-h-screen max-w-[1700px] flex-col lg:grid lg:grid-cols-[240px_1fr_320px]">
            <aside class="border-b border-zinc-800/80 bg-zinc-950/80 px-4 py-4 lg:border-r lg:border-b-0 lg:py-5">
                <div class="mb-8 flex items-center gap-2 px-2">
                    <div class="h-2.5 w-2.5 rounded-full bg-violet-400"></div>
                    <p class="text-sm font-medium tracking-wide text-zinc-200">Mission Control</p>
                </div>

                <nav class="flex gap-1 overflow-x-auto pb-1 lg:block lg:space-y-1">
                    @foreach (['Overview', 'Tasks', 'Roadmap', 'People', 'Reports', 'Settings'] as $index => $section)
                        <button class="{{ $index === 1 ? 'border border-zinc-700 bg-zinc-900 text-zinc-100' : 'text-zinc-400 hover:bg-zinc-900/60 hover:text-zinc-200' }} whitespace-nowrap rounded-md px-3 py-2 text-left text-sm transition lg:w-full">
                            {{ $section }}
                        </button>
                    @endforeach
                </nav>
            </aside>

            <section class="flex min-w-0 flex-col">
                <header class="flex flex-col gap-3 border-b border-zinc-800/80 px-4 py-4 sm:px-6 lg:flex-row lg:items-center lg:justify-between">
                    <div class="w-full max-w-md rounded-md border border-zinc-800 bg-zinc-900/80 px-3 py-2 text-sm text-zinc-400">Search tasks, projects, status...</div>
                    <div class="flex items-center gap-2 lg:ml-4">
                        <span id="updated-label" class="text-xs text-zinc-400">Loading live data...</span>
                        <button class="rounded-md border border-zinc-800 bg-zinc-900 px-3 py-1.5 text-xs text-zinc-300 hover:bg-zinc-800">New task</button>
                    </div>
                </header>

                <main class="flex-1 overflow-auto px-4 py-5 sm:px-6">
                    <div id="error-banner" class="mb-4 hidden rounded-md border border-rose-900/60 bg-rose-950/20 px-3 py-2 text-xs text-rose-300"></div>

                    <div id="status-strip" class="mb-5 grid gap-2 rounded-lg border border-zinc-800 bg-zinc-900/60 p-3 sm:grid-cols-2 xl:grid-cols-4"></div>

                    <section class="mb-5 rounded-lg border border-zinc-800 bg-zinc-900/60 p-3">
                        <div class="mb-3 flex items-center justify-between">
                            <h2 class="text-sm font-medium text-zinc-100">Week View</h2>
                            <p class="text-[11px] text-zinc-500">Monday to Sunday</p>
                        </div>
                        <div id="week-grid" class="grid gap-3 md:grid-cols-2 2xl:grid-cols-4"></div>
                        <div class="mt-3 rounded-md border border-zinc-800/80 bg-zinc-950/60 p-3">
                            <h3 class="text-xs font-medium text-zinc-200">Unscheduled</h3>
                            <div id="week-unscheduled" class="mt-2"></div>
                        </div>
                    </section>

                    <div id="columns-grid" class="grid gap-4 md:grid-cols-2 xl:grid-cols-3"></div>
                </main>
            </section>

            <aside class="border-t border-zinc-800/80 bg-zinc-950/70 px-4 py-5 lg:border-l lg:border-t-0">
                <h3 class="text-sm font-medium text-zinc-200">Live active tasks/processes</h3>
                <div id="live-processes" class="mt-3 space-y-2"></div>

                <h3 class="mt-6 text-sm font-medium text-zinc-200">Source diagnostics</h3>
                <div id="source-diagnostics" class="mt-3 min-h-56 space-y-2 rounded-md border border-zinc-800 bg-zinc-900/50 p-3 text-xs"></div>
            </aside>
        </div>

        <script>
            const columnMeta = [
                { key: 'planned', title: 'Planned' },
                { key: 'backlog', title: 'Backlog' },
                { key: 'active', title: 'Active' },
                { key: 'review', title: 'Needs your review' },
                { key: 'done', title: 'Done' },
            ];

            let mission = @json($initialMission);
            let loading = false;
            let lastUpdatedAt = Date.now();

            const esc = (value = '') => String(value)
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;')
                .replaceAll("'", '&#39;');

            function setUpdatedLabel() {
                const label = document.getElementById('updated-label');
                if (!mission?.fetchedAt) {
                    label.textContent = loading ? 'Loading live data...' : 'No successful fetch yet';
                    label.className = 'text-xs text-zinc-400';
                    return;
                }

                const stale = Date.now() - lastUpdatedAt > 40000;
                const date = new Date(mission.fetchedAt);
                const readable = Number.isNaN(date.getTime()) ? mission.fetchedAt : date.toLocaleTimeString();

                label.textContent = `Updated ${readable}${stale ? ' (stale)' : ''}`;
                label.className = `text-xs ${stale ? 'text-amber-300' : 'text-zinc-400'}`;
            }

            function renderStatus() {
                const container = document.getElementById('status-strip');
                const items = mission?.statusItems || [];
                if (!items.length) {
                    container.innerHTML = '<p class="col-span-full text-xs text-zinc-500">No status data from local sources.</p>';
                    return;
                }

                container.innerHTML = items.map((item) => `
                    <div class="rounded-md border border-zinc-800/80 bg-zinc-950/80 px-3 py-2">
                        <p class="text-[11px] uppercase tracking-wide text-zinc-500">${esc(item.name)}</p>
                        <p class="mt-1 text-sm font-medium ${esc(item.tone || 'text-zinc-300')}">${esc(item.status)}</p>
                    </div>
                `).join('');
            }

            function renderWeek() {
                const weekGrid = document.getElementById('week-grid');
                const unscheduled = document.getElementById('week-unscheduled');
                const typeTone = {
                    planned: 'text-sky-300',
                    backlog: 'text-violet-300',
                    active: 'text-emerald-300',
                    review: 'text-amber-300',
                    done: 'text-zinc-300',
                };

                weekGrid.innerHTML = (mission?.week || []).map((day) => {
                    const entries = (day.items || []).length
                        ? `<ul class="space-y-1.5">${day.items.map((item) => `
                            <li class="rounded border border-zinc-800 bg-zinc-900/70 px-2 py-1.5">
                                <p class="text-[11px] text-zinc-200">${esc(item.title)}</p>
                                <p class="mt-1 text-[10px] text-zinc-500">
                                    <span class="${typeTone[item.type] || 'text-zinc-400'}">${esc(item.type || '')}</span>
                                    ${item.time ? ` • ${esc(item.time)}` : ''}
                                    ${item.source ? ` • ${esc(item.source)}` : ''}
                                </p>
                            </li>
                        `).join('')}</ul>`
                        : '<p class="rounded border border-dashed border-zinc-700 bg-zinc-900/40 px-2 py-1.5 text-[11px] text-zinc-500">No scheduled items.</p>';

                    return `
                        <article class="rounded-md border border-zinc-800/80 bg-zinc-950/70 p-3">
                            <div class="mb-2 flex items-center justify-between">
                                <h3 class="text-xs font-medium text-zinc-200">${esc(day.label)}</h3>
                                <span class="text-[11px] text-zinc-500">${esc(day.date)}</span>
                            </div>
                            ${entries}
                        </article>
                    `;
                }).join('');

                const unscheduledItems = mission?.weekUnscheduled || [];
                unscheduled.innerHTML = unscheduledItems.length
                    ? `<ul class="space-y-1">${unscheduledItems.map((item) => `
                        <li class="rounded border border-zinc-800 bg-zinc-900/70 px-2 py-1.5 text-[11px] text-zinc-300">
                            ${esc(item.title)}
                            <span class="ml-2 text-[10px] text-zinc-500">${esc(item.type || '')}${item.source ? ` • ${esc(item.source)}` : ''}</span>
                        </li>
                    `).join('')}</ul>`
                    : '<p class="text-[11px] text-zinc-500">No unscheduled tasks from current sources.</p>';
            }

            function renderColumns() {
                const container = document.getElementById('columns-grid');
                container.innerHTML = columnMeta.map((column) => {
                    const items = mission?.columns?.[column.key] || [];
                    const body = items.length
                        ? `<ul class="mt-3 space-y-2">${items.map((item) => `
                            <li class="rounded-md border border-zinc-800/80 bg-zinc-950/70 px-3 py-2 text-xs text-zinc-300">${esc(item)}</li>
                        `).join('')}</ul>`
                        : '<p class="mt-3 rounded-md border border-dashed border-zinc-700 bg-zinc-950/60 px-3 py-2 text-xs text-zinc-500">No live items right now.</p>';

                    return `<article class="rounded-lg border border-zinc-800 bg-zinc-900/50 p-4">
                        <h2 class="text-sm font-medium text-zinc-100">${column.title}</h2>
                        ${body}
                    </article>`;
                }).join('');
            }

            function renderProcesses() {
                const container = document.getElementById('live-processes');
                const entries = mission?.liveProcesses || [];
                container.innerHTML = entries.length
                    ? entries.map((proc) => `
                        <div class="rounded-md border border-zinc-800 bg-zinc-900/60 p-3">
                            <p class="text-xs font-medium text-zinc-200">${esc(proc.name)}</p>
                            <p class="mt-1 text-[11px] text-zinc-400">${esc(proc.detail)}</p>
                            <p class="mt-1 text-[11px] text-emerald-300">${esc(proc.state)}</p>
                        </div>
                    `).join('')
                    : '<p class="rounded-md border border-dashed border-zinc-700 bg-zinc-900/50 p-3 text-xs text-zinc-500">No matching live processes found.</p>';
            }

            function renderSources() {
                const container = document.getElementById('source-diagnostics');
                const entries = mission?.sources || [];
                container.innerHTML = entries.length
                    ? entries.map((source) => `
                        <div class="rounded border border-zinc-800 bg-zinc-950/70 px-2 py-1.5">
                            <p class="text-zinc-300">${esc(source.name)}</p>
                            <p class="${source.ok ? 'text-emerald-300' : 'text-amber-300'}">${esc(source.message)}</p>
                        </div>
                    `).join('')
                    : '<p class="text-zinc-500">No source checks yet.</p>';
            }

            function renderError(message = '') {
                const error = document.getElementById('error-banner');
                if (!message) {
                    error.classList.add('hidden');
                    error.textContent = '';
                    return;
                }

                error.classList.remove('hidden');
                error.textContent = message;
            }

            function renderAll() {
                setUpdatedLabel();
                renderStatus();
                renderWeek();
                renderColumns();
                renderProcesses();
                renderSources();
            }

            async function refreshMission() {
                loading = true;
                setUpdatedLabel();

                try {
                    const response = await fetch('/api/mission', { headers: { 'Accept': 'application/json' } });
                    if (!response.ok) {
                        throw new Error(`Failed to fetch mission data (${response.status})`);
                    }

                    mission = await response.json();
                    lastUpdatedAt = Date.now();
                    renderError('');
                } catch (error) {
                    renderError(error?.message || 'Unable to load mission data');
                } finally {
                    loading = false;
                    renderAll();
                }
            }

            renderAll();
            window.setInterval(refreshMission, 20000);
            refreshMission();
        </script>
    </body>
</html>
