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
        <div class="mx-auto min-h-screen w-full max-w-6xl px-4 py-4 sm:px-6 lg:py-6">
            <header class="mb-4 flex items-center justify-between gap-3 border-b border-zinc-800/80 pb-3">
                <div>
                    <h1 class="text-lg font-semibold text-zinc-100">Mission Control</h1>
                    <p class="text-xs text-zinc-500">Intern samarbeidstavle for Anders og assistent</p>
                </div>
                <span id="updated-label" class="text-xs text-zinc-400">Laster data...</span>
            </header>

            <div id="error-banner" class="mb-3 hidden rounded-md border border-rose-900/60 bg-rose-950/20 px-3 py-2 text-xs text-rose-300"></div>

            <section class="mb-4">
                <div id="status-strip" class="grid gap-2 rounded-lg border border-zinc-800 bg-zinc-900/60 p-2 sm:grid-cols-2 lg:grid-cols-4"></div>
            </section>

            <main class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <article class="rounded-lg border border-zinc-800 bg-zinc-900/60 p-3">
                    <h2 class="text-sm font-semibold text-zinc-100">Nå</h2>
                    <p class="mt-1 text-[11px] text-zinc-500">Høyest prioriterte aktive eller review-oppgaver</p>
                    <ul id="now-items" class="mt-3 space-y-2"></ul>
                </article>

                <article class="rounded-lg border border-zinc-800 bg-zinc-900/60 p-3">
                    <h2 class="text-sm font-semibold text-zinc-100">Denne uka</h2>
                    <p class="mt-1 text-[11px] text-zinc-500">Kondensert plan mandag til søndag</p>
                    <ul id="week-items" class="mt-3 space-y-2"></ul>
                </article>

                <article class="rounded-lg border border-zinc-800 bg-zinc-900/60 p-3 sm:col-span-2 lg:col-span-1">
                    <h2 class="text-sm font-semibold text-zinc-100">Venter på Anders</h2>
                    <p class="mt-1 text-[11px] text-zinc-500">Godkjenning, review eller eksplisitte ventepunkter</p>
                    <ul id="waiting-items" class="mt-3 space-y-2"></ul>
                </article>
            </main>

            <section class="mt-4 space-y-3">
                <details class="rounded-lg border border-zinc-800 bg-zinc-900/40 p-3" open>
                    <summary class="cursor-pointer text-sm font-medium text-zinc-200">Sekundære detaljer</summary>
                    <div class="mt-3 space-y-3">
                        <div>
                            <h3 class="text-xs font-medium text-zinc-300">Kolonner</h3>
                            <div id="columns-grid" class="mt-2 grid gap-3 md:grid-cols-2"></div>
                        </div>

                        <div>
                            <h3 class="text-xs font-medium text-zinc-300">Uplanlagte punkter</h3>
                            <div id="week-unscheduled" class="mt-2"></div>
                        </div>
                    </div>
                </details>

                <details class="rounded-lg border border-zinc-800 bg-zinc-900/40 p-3">
                    <summary class="cursor-pointer text-sm font-medium text-zinc-200">Live prosesser og kildediagnostikk</summary>
                    <div class="mt-3 grid gap-3 lg:grid-cols-2">
                        <div>
                            <h3 class="text-xs font-medium text-zinc-300">Live prosesser</h3>
                            <div id="live-processes" class="mt-2 space-y-2"></div>
                        </div>
                        <div>
                            <h3 class="text-xs font-medium text-zinc-300">Kilder</h3>
                            <div id="source-diagnostics" class="mt-2 space-y-2 rounded-md border border-zinc-800 bg-zinc-900/50 p-3 text-xs"></div>
                        </div>
                    </div>
                </details>
            </section>
        </div>

        <script>
            const columnMeta = [
                { key: 'planned', title: 'Planned' },
                { key: 'backlog', title: 'Backlog' },
                { key: 'active', title: 'Active' },
                { key: 'review', title: 'Needs review' },
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
                    label.textContent = loading ? 'Laster live data...' : 'Ingen vellykket oppdatering ennå';
                    label.className = 'text-xs text-zinc-400';
                    return;
                }

                const stale = Date.now() - lastUpdatedAt > 40000;
                const date = new Date(mission.fetchedAt);
                const readable = Number.isNaN(date.getTime()) ? mission.fetchedAt : date.toLocaleTimeString();

                label.textContent = `Oppdatert ${readable}${stale ? ' (gammel)' : ''}`;
                label.className = `text-xs ${stale ? 'text-amber-300' : 'text-zinc-400'}`;
            }

            function renderStatus() {
                const container = document.getElementById('status-strip');
                const items = mission?.statusItems || [];
                if (!items.length) {
                    container.innerHTML = '<p class="col-span-full text-xs text-zinc-500">Ingen statusdata tilgjengelig.</p>';
                    return;
                }

                container.innerHTML = items.map((item) => `
                    <div class="rounded-md border border-zinc-800/80 bg-zinc-950/80 px-2 py-1.5">
                        <p class="text-[10px] uppercase tracking-wide text-zinc-500">${esc(item.name)}</p>
                        <p class="text-xs font-medium ${esc(item.tone || 'text-zinc-300')}">${esc(item.status)}</p>
                    </div>
                `).join('');
            }

            function renderNowItems() {
                const container = document.getElementById('now-items');
                const items = mission?.nowItems || [];

                container.innerHTML = items.length
                    ? items.map((item) => `
                        <li class="rounded-md border border-zinc-800/80 bg-zinc-950/70 px-2 py-1.5">
                            <p class="text-xs text-zinc-200">• ${esc(item.title)}</p>
                            <p class="mt-1 text-[10px] text-zinc-500">${esc(item.type)} • ${esc(item.source)} • score ${esc(item.score)}</p>
                        </li>
                    `).join('')
                    : '<li class="rounded border border-dashed border-zinc-700 bg-zinc-950/50 px-2 py-1.5 text-xs text-zinc-500">Ingen aktive høy-prio punkter akkurat nå.</li>';
            }

            function renderWeekItems() {
                const container = document.getElementById('week-items');
                const days = mission?.weekItems || [];
                const condensed = days.filter((day) => (day.items || []).length);

                container.innerHTML = condensed.length
                    ? condensed.map((day) => `
                        <li class="rounded-md border border-zinc-800/80 bg-zinc-950/70 px-2 py-1.5 text-xs text-zinc-300">
                            <span class="font-medium text-zinc-200">${esc(day.label)}:</span>
                            ${esc(day.items.join(' • '))}
                        </li>
                    `).join('')
                    : '<li class="rounded border border-dashed border-zinc-700 bg-zinc-950/50 px-2 py-1.5 text-xs text-zinc-500">Ingen planlagte nøkkelpunkter denne uka.</li>';
            }

            function renderWaitingItems() {
                const container = document.getElementById('waiting-items');
                const items = mission?.waitingItems || [];

                container.innerHTML = items.length
                    ? items.map((item) => `
                        <li class="rounded-md border border-zinc-800/80 bg-zinc-950/70 px-2 py-1.5">
                            <p class="text-xs text-zinc-200">• ${esc(item.title)}</p>
                            <p class="mt-1 text-[10px] text-zinc-500">${esc(item.type)} • ${esc(item.source)} • score ${esc(item.score)}</p>
                        </li>
                    `).join('')
                    : '<li class="rounded border border-dashed border-zinc-700 bg-zinc-950/50 px-2 py-1.5 text-xs text-zinc-500">Ingen ventende beslutninger.</li>';
            }

            function renderColumns() {
                const container = document.getElementById('columns-grid');
                container.innerHTML = columnMeta.map((column) => {
                    const items = mission?.columns?.[column.key] || [];
                    const body = items.length
                        ? `<ul class="mt-2 space-y-1.5">${items.slice(0, 5).map((item) => `
                            <li class="rounded-md border border-zinc-800/80 bg-zinc-950/70 px-2 py-1.5 text-[11px] text-zinc-300">${esc(item)}</li>
                        `).join('')}</ul>`
                        : '<p class="mt-2 rounded-md border border-dashed border-zinc-700 bg-zinc-950/60 px-2 py-1.5 text-[11px] text-zinc-500">Ingen data.</p>';

                    return `<article class="rounded-lg border border-zinc-800 bg-zinc-900/40 p-3">
                        <h2 class="text-xs font-medium text-zinc-100">${column.title}</h2>
                        ${body}
                    </article>`;
                }).join('');
            }

            function renderUnscheduled() {
                const container = document.getElementById('week-unscheduled');
                const unscheduledItems = mission?.weekUnscheduled || [];
                container.innerHTML = unscheduledItems.length
                    ? `<ul class="space-y-1">${unscheduledItems.slice(0, 10).map((item) => `
                        <li class="rounded border border-zinc-800 bg-zinc-900/70 px-2 py-1.5 text-[11px] text-zinc-300">
                            ${esc(item.title)}
                            <span class="ml-2 text-[10px] text-zinc-500">${esc(item.type || '')}${item.source ? ` • ${esc(item.source)}` : ''}</span>
                        </li>
                    `).join('')}</ul>`
                    : '<p class="text-[11px] text-zinc-500">Ingen uplanlagte punkter.</p>';
            }

            function renderProcesses() {
                const container = document.getElementById('live-processes');
                const entries = mission?.liveProcesses || [];
                container.innerHTML = entries.length
                    ? entries.map((proc) => `
                        <div class="rounded-md border border-zinc-800 bg-zinc-900/60 p-2">
                            <p class="text-xs font-medium text-zinc-200">${esc(proc.name)}</p>
                            <p class="mt-1 text-[11px] text-zinc-400">${esc(proc.detail)}</p>
                            <p class="mt-1 text-[11px] text-emerald-300">${esc(proc.state)}</p>
                        </div>
                    `).join('')
                    : '<p class="rounded-md border border-dashed border-zinc-700 bg-zinc-900/50 p-2 text-xs text-zinc-500">Ingen relevante prosesser.</p>';
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
                    : '<p class="text-zinc-500">Ingen kildechecks ennå.</p>';
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
                renderNowItems();
                renderWeekItems();
                renderWaitingItems();
                renderColumns();
                renderUnscheduled();
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
