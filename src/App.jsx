import { useEffect, useMemo, useState } from 'react'

const navSections = ['Overview', 'Tasks', 'Roadmap', 'People', 'Reports', 'Settings']

const emptyPayload = {
  statusItems: [],
  columns: {
    planned: [],
    backlog: [],
    active: [],
    review: [],
    done: [],
  },
  week: [],
  weekUnscheduled: [],
  liveProcesses: [],
  fetchedAt: null,
  sources: [],
}

const columnMeta = [
  { key: 'planned', title: 'Planned' },
  { key: 'backlog', title: 'Backlog' },
  { key: 'active', title: 'Active' },
  { key: 'review', title: 'Needs your review' },
  { key: 'done', title: 'Done' },
]

function SectionCard({ title, items }) {
  return (
    <article className="rounded-lg border border-zinc-800 bg-zinc-900/50 p-4">
      <h2 className="text-sm font-medium text-zinc-100">{title}</h2>
      {items.length === 0 ? (
        <p className="mt-3 rounded-md border border-dashed border-zinc-700 bg-zinc-950/60 px-3 py-2 text-xs text-zinc-500">
          No live items right now.
        </p>
      ) : (
        <ul className="mt-3 space-y-2">
          {items.map((item) => (
            <li key={item} className="rounded-md border border-zinc-800/80 bg-zinc-950/70 px-3 py-2 text-xs text-zinc-300">
              {item}
            </li>
          ))}
        </ul>
      )}
    </article>
  )
}

function WeekView({ week, unscheduled }) {
  const typeTone = {
    planned: 'text-sky-300',
    backlog: 'text-violet-300',
    active: 'text-emerald-300',
    review: 'text-amber-300',
    done: 'text-zinc-300',
  }

  return (
    <section className="mb-5 rounded-lg border border-zinc-800 bg-zinc-900/60 p-3">
      <div className="mb-3 flex items-center justify-between">
        <h2 className="text-sm font-medium text-zinc-100">Week View</h2>
        <p className="text-[11px] text-zinc-500">Monday to Sunday</p>
      </div>

      <div className="grid gap-3 md:grid-cols-2 2xl:grid-cols-4">
        {week.map((day) => (
          <article key={day.dayKey} className="rounded-md border border-zinc-800/80 bg-zinc-950/70 p-3">
            <div className="mb-2 flex items-center justify-between">
              <h3 className="text-xs font-medium text-zinc-200">{day.label}</h3>
              <span className="text-[11px] text-zinc-500">{day.date}</span>
            </div>

            {day.items.length === 0 ? (
              <p className="rounded border border-dashed border-zinc-700 bg-zinc-900/40 px-2 py-1.5 text-[11px] text-zinc-500">
                No scheduled items.
              </p>
            ) : (
              <ul className="space-y-1.5">
                {day.items.map((item, index) => (
                  <li key={`${day.dayKey}-${item.title}-${index}`} className="rounded border border-zinc-800 bg-zinc-900/70 px-2 py-1.5">
                    <p className="text-[11px] text-zinc-200">{item.title}</p>
                    <p className="mt-1 text-[10px] text-zinc-500">
                      <span className={typeTone[item.type] || 'text-zinc-400'}>{item.type}</span>
                      {item.time ? ` • ${item.time}` : ''}
                      {item.source ? ` • ${item.source}` : ''}
                    </p>
                  </li>
                ))}
              </ul>
            )}
          </article>
        ))}
      </div>

      <div className="mt-3 rounded-md border border-zinc-800/80 bg-zinc-950/60 p-3">
        <h3 className="text-xs font-medium text-zinc-200">Unscheduled</h3>
        {unscheduled.length === 0 ? (
          <p className="mt-2 text-[11px] text-zinc-500">No unscheduled tasks from current sources.</p>
        ) : (
          <ul className="mt-2 space-y-1">
            {unscheduled.map((item, index) => (
              <li key={`${item.title}-${index}`} className="rounded border border-zinc-800 bg-zinc-900/70 px-2 py-1.5 text-[11px] text-zinc-300">
                {item.title}
                <span className="ml-2 text-[10px] text-zinc-500">
                  {item.type}
                  {item.source ? ` • ${item.source}` : ''}
                </span>
              </li>
            ))}
          </ul>
        )}
      </div>
    </section>
  )
}

function App() {
  const [mission, setMission] = useState(emptyPayload)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [lastUpdatedAt, setLastUpdatedAt] = useState(null)

  useEffect(() => {
    let ignore = false

    const fetchMission = async ({ isInitial = false } = {}) => {
      try {
        const response = await fetch('/api/mission')
        if (!response.ok) {
          throw new Error(`Failed to fetch mission data (${response.status})`)
        }

        const payload = await response.json()

        if (!ignore) {
          setMission({ ...emptyPayload, ...payload })
          setError('')
          setLastUpdatedAt(Date.now())
        }
      } catch (fetchError) {
        if (!ignore) {
          setError(fetchError.message || 'Unable to load mission data')
        }
      } finally {
        if (!ignore && isInitial) {
          setLoading(false)
        }
      }
    }

    fetchMission({ isInitial: true })
    const interval = window.setInterval(() => fetchMission(), 20_000)

    return () => {
      ignore = true
      window.clearInterval(interval)
    }
  }, [])

  const stale = useMemo(() => {
    if (!lastUpdatedAt) return false
    return Date.now() - lastUpdatedAt > 40_000
  }, [lastUpdatedAt, mission.fetchedAt])

  const lastUpdatedLabel = useMemo(() => {
    if (!mission.fetchedAt) return 'No successful fetch yet'

    const date = new Date(mission.fetchedAt)
    if (Number.isNaN(date.getTime())) return mission.fetchedAt
    return date.toLocaleTimeString()
  }, [mission.fetchedAt])

  return (
    <div className="min-h-screen bg-zinc-950 text-zinc-100 antialiased">
      <div className="mx-auto flex min-h-screen max-w-[1700px] flex-col lg:grid lg:grid-cols-[240px_1fr_320px]">
        <aside className="border-b border-zinc-800/80 bg-zinc-950/80 px-4 py-4 lg:border-r lg:border-b-0 lg:py-5">
          <div className="mb-8 flex items-center gap-2 px-2">
            <div className="h-2.5 w-2.5 rounded-full bg-violet-400" />
            <p className="text-sm font-medium tracking-wide text-zinc-200">Mission Control</p>
          </div>

          <nav className="flex gap-1 overflow-x-auto pb-1 lg:block lg:space-y-1">
            {navSections.map((section, index) => (
              <button
                key={section}
                className={`whitespace-nowrap rounded-md px-3 py-2 text-left text-sm transition lg:w-full ${
                  index === 1
                    ? 'border border-zinc-700 bg-zinc-900 text-zinc-100'
                    : 'text-zinc-400 hover:bg-zinc-900/60 hover:text-zinc-200'
                }`}
              >
                {section}
              </button>
            ))}
          </nav>
        </aside>

        <section className="flex min-w-0 flex-col">
          <header className="flex flex-col gap-3 border-b border-zinc-800/80 px-4 py-4 sm:px-6 lg:flex-row lg:items-center lg:justify-between">
            <div className="w-full max-w-md rounded-md border border-zinc-800 bg-zinc-900/80 px-3 py-2 text-sm text-zinc-400">
              Search tasks, projects, status...
            </div>
            <div className="flex items-center gap-2 lg:ml-4">
              <span className={`text-xs ${stale ? 'text-amber-300' : 'text-zinc-400'}`}>
                {loading ? 'Loading live data...' : `Updated ${lastUpdatedLabel}${stale ? ' (stale)' : ''}`}
              </span>
              <button className="rounded-md border border-zinc-800 bg-zinc-900 px-3 py-1.5 text-xs text-zinc-300 hover:bg-zinc-800">
                New task
              </button>
            </div>
          </header>

          <main className="flex-1 overflow-auto px-4 py-5 sm:px-6">
            {error ? (
              <div className="mb-4 rounded-md border border-rose-900/60 bg-rose-950/20 px-3 py-2 text-xs text-rose-300">
                {error}
              </div>
            ) : null}

            <div className="mb-5 grid gap-2 rounded-lg border border-zinc-800 bg-zinc-900/60 p-3 sm:grid-cols-2 xl:grid-cols-4">
              {mission.statusItems.length > 0 ? (
                mission.statusItems.map((item) => (
                  <div key={item.name} className="rounded-md border border-zinc-800/80 bg-zinc-950/80 px-3 py-2">
                    <p className="text-[11px] uppercase tracking-wide text-zinc-500">{item.name}</p>
                    <p className={`mt-1 text-sm font-medium ${item.tone || 'text-zinc-300'}`}>{item.status}</p>
                  </div>
                ))
              ) : (
                <p className="col-span-full text-xs text-zinc-500">No status data from local sources.</p>
              )}
            </div>

            <WeekView week={mission.week || []} unscheduled={mission.weekUnscheduled || []} />

            <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
              {columnMeta.map((column) => (
                <SectionCard key={column.key} title={column.title} items={mission.columns[column.key] || []} />
              ))}
            </div>
          </main>
        </section>

        <aside className="border-t border-zinc-800/80 bg-zinc-950/70 px-4 py-5 lg:border-l lg:border-t-0">
          <h3 className="text-sm font-medium text-zinc-200">Live active tasks/processes</h3>
          <div className="mt-3 space-y-2">
            {mission.liveProcesses.length === 0 ? (
              <p className="rounded-md border border-dashed border-zinc-700 bg-zinc-900/50 p-3 text-xs text-zinc-500">
                No matching live processes found.
              </p>
            ) : (
              mission.liveProcesses.map((proc) => (
                <div key={`${proc.name}-${proc.detail}`} className="rounded-md border border-zinc-800 bg-zinc-900/60 p-3">
                  <p className="text-xs font-medium text-zinc-200">{proc.name}</p>
                  <p className="mt-1 text-[11px] text-zinc-400">{proc.detail}</p>
                  <p className="mt-1 text-[11px] text-emerald-300">{proc.state}</p>
                </div>
              ))
            )}
          </div>

          <h3 className="mt-6 text-sm font-medium text-zinc-200">Source diagnostics</h3>
          <div className="mt-3 min-h-56 space-y-2 rounded-md border border-zinc-800 bg-zinc-900/50 p-3 text-xs">
            {mission.sources.length === 0 ? (
              <p className="text-zinc-500">No source checks yet.</p>
            ) : (
              mission.sources.map((source) => (
                <div key={source.name} className="rounded border border-zinc-800 bg-zinc-950/70 px-2 py-1.5">
                  <p className="text-zinc-300">{source.name}</p>
                  <p className={source.ok ? 'text-emerald-300' : 'text-amber-300'}>{source.message}</p>
                </div>
              ))
            )}
          </div>
        </aside>
      </div>
    </div>
  )
}

export default App
