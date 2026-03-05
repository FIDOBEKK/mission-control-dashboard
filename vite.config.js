import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import { execFile } from 'node:child_process'
import { existsSync } from 'node:fs'
import { promisify } from 'node:util'

const execFileAsync = promisify(execFile)
const DEFAULT_TIMEOUT_MS = 2500
const DAY_DEFS = [
  { key: 'monday', label: 'Monday' },
  { key: 'tuesday', label: 'Tuesday' },
  { key: 'wednesday', label: 'Wednesday' },
  { key: 'thursday', label: 'Thursday' },
  { key: 'friday', label: 'Friday' },
  { key: 'saturday', label: 'Saturday' },
  { key: 'sunday', label: 'Sunday' },
]

const repoConfigs = [
  {
    slug: 'iglebekk/OnePagerHub',
    localCandidates: [
      '/Users/andersiglebekk/Documents/OnePagerHub',
      '/Users/andersiglebekk/Documents/onepagerhub',
    ],
  },
  {
    slug: 'FIDOBEKK/frame-generator',
    localCandidates: [
      '/Users/andersiglebekk/Documents/frame-generator',
      '/Users/andersiglebekk/Documents/FrameGenerator',
    ],
  },
]

const processKeywords = ['openclaw', 'vite', 'ollama', 'node', 'npm', 'pnpm', 'python', 'claude', 'codex']

async function runCommand(name, file, args = [], options = {}) {
  const timeoutMs = options.timeoutMs ?? DEFAULT_TIMEOUT_MS

  try {
    const { stdout, stderr } = await execFileAsync(file, args, {
      cwd: options.cwd,
      timeout: timeoutMs,
      maxBuffer: 1024 * 1024,
      env: process.env,
    })

    return {
      ok: true,
      stdout: stdout?.trim() ?? '',
      stderr: stderr?.trim() ?? '',
      source: {
        name,
        ok: true,
        message: 'ok',
      },
    }
  } catch (error) {
    const code = typeof error?.code === 'string' ? error.code : error?.code ?? 'ERR'
    const message = error?.message ?? 'command failed'

    return {
      ok: false,
      stdout: error?.stdout?.trim?.() ?? '',
      stderr: error?.stderr?.trim?.() ?? '',
      source: {
        name,
        ok: false,
        message: `${code}: ${message}`,
      },
    }
  }
}

function safeJsonParse(text, fallback) {
  if (!text) return fallback

  try {
    return JSON.parse(text)
  } catch {
    return fallback
  }
}

function pickExistingRepoPaths(configs) {
  return configs
    .map((repo) => {
      const path = repo.localCandidates.find((candidate) => existsSync(candidate))
      return {
        ...repo,
        localPath: path,
      }
    })
    .filter((repo) => repo.localPath)
}

function normalizeIssueItems(issues = []) {
  return issues.map((issue) => `#${issue.number} ${issue.title}`)
}

function normalizePrItems(prs = []) {
  return prs.map((pr) => `#${pr.number} ${pr.title}`)
}

function normalizeCommitItems(repoSlug, commits = []) {
  return commits.map((commit) => {
    const [hash, subject, ago] = commit.split('|')
    return `${repoSlug} ${hash} ${subject}${ago ? ` (${ago})` : ''}`
  })
}

function formatSchedule(value) {
  if (typeof value === 'string') return value
  if (Array.isArray(value)) return value.join(' ')
  if (value && typeof value === 'object') {
    return value.expression || value.cron || value.value || JSON.stringify(value)
  }
  if (value === undefined || value === null) return ''
  return String(value)
}

function getCurrentWeekEntries() {
  const now = new Date()
  const dayOffset = (now.getDay() + 6) % 7
  const monday = new Date(now)
  monday.setHours(0, 0, 0, 0)
  monday.setDate(monday.getDate() - dayOffset)

  return DAY_DEFS.map((day, index) => {
    const date = new Date(monday)
    date.setDate(monday.getDate() + index)

    return {
      dayKey: day.key,
      date: date.toISOString().slice(0, 10),
      label: day.label,
      items: [],
    }
  })
}

function parseCronDays(scheduleText = '') {
  const schedule = String(scheduleText ?? '').trim().toLowerCase()
  if (!schedule) return []

  const tokens = schedule.split(/\s+/)
  if (tokens.length < 5) return []

  const dayField = tokens[4]
  if (!dayField || dayField === '*') return DAY_DEFS.map((day) => day.key)

  const values = new Set()
  const parts = dayField.split(',').map((part) => part.trim())

  for (const part of parts) {
    if (!part) continue

    if (part.includes('-')) {
      const [rawStart, rawEnd] = part.split('-')
      const start = Number(rawStart)
      const end = Number(rawEnd)

      if (Number.isInteger(start) && Number.isInteger(end)) {
        for (let i = start; i <= end; i += 1) {
          values.add(i)
        }
      }
      continue
    }

    const value = Number(part)
    if (Number.isInteger(value)) {
      values.add(value)
    }
  }

  if (values.size === 0) return []

  return DAY_DEFS.filter((_, index) => {
    const cronValue = index + 1
    const sundayMatch = index === 6 && (values.has(0) || values.has(7))
    return values.has(cronValue) || sundayMatch
  }).map((day) => day.key)
}

function parseCronTime(scheduleText = '') {
  const tokens = String(scheduleText ?? '').trim().split(/\s+/)
  if (tokens.length < 2) return null

  const [minute, hour] = tokens
  if (!/^\d+$/.test(minute) || !/^\d+$/.test(hour)) return null

  const hh = hour.padStart(2, '0')
  const mm = minute.padStart(2, '0')
  return `${hh}:${mm}`
}

function inferDayKeysFromText(text = '') {
  const value = text.toLowerCase()
  if (!value) return []

  if (value.includes('daily') || value.includes('every day')) {
    return DAY_DEFS.map((day) => day.key)
  }

  if (value.includes('weekday')) {
    return DAY_DEFS.slice(0, 5).map((day) => day.key)
  }

  if (value.includes('weekend')) {
    return DAY_DEFS.slice(5).map((day) => day.key)
  }

  const matches = []
  const map = {
    monday: ['monday', 'mon'],
    tuesday: ['tuesday', 'tue'],
    wednesday: ['wednesday', 'wed'],
    thursday: ['thursday', 'thu'],
    friday: ['friday', 'fri'],
    saturday: ['saturday', 'sat'],
    sunday: ['sunday', 'sun'],
  }

  for (const [dayKey, aliases] of Object.entries(map)) {
    if (aliases.some((alias) => value.includes(alias))) {
      matches.push(dayKey)
    }
  }

  return matches
}

function buildWeekOverview({ cronJobs, columns }) {
  const week = getCurrentWeekEntries()
  const weekMap = new Map(week.map((day) => [day.dayKey, day]))
  const weekUnscheduled = []

  for (const job of cronJobs) {
    const title = job?.name || job?.id || job?.command || 'Cron job'
    const schedule = formatSchedule(job?.schedule)
    const dayKeys = parseCronDays(schedule)
    const time = parseCronTime(schedule)

    if (dayKeys.length === 0) {
      weekUnscheduled.push({
        type: 'planned',
        title,
        time,
        source: 'openclaw cron',
      })
      continue
    }

    for (const dayKey of dayKeys) {
      const day = weekMap.get(dayKey)
      if (!day) continue
      day.items.push({
        type: 'planned',
        title,
        time,
        source: 'openclaw cron',
      })
    }
  }

  const mappingSources = [
    { key: 'planned', source: 'mission planned' },
    { key: 'backlog', source: 'github issue' },
    { key: 'active', source: 'active work' },
    { key: 'review', source: 'github pr' },
    { key: 'done', source: 'git log' },
  ]

  for (const bucket of mappingSources) {
    const items = Array.isArray(columns[bucket.key]) ? columns[bucket.key] : []

    for (const itemTitle of items) {
      const dayKeys = inferDayKeysFromText(itemTitle)

      if (dayKeys.length === 0) {
        weekUnscheduled.push({
          type: bucket.key,
          title: itemTitle,
          source: bucket.source,
        })
        continue
      }

      for (const dayKey of dayKeys) {
        const day = weekMap.get(dayKey)
        if (!day) continue
        day.items.push({
          type: bucket.key,
          title: itemTitle,
          source: bucket.source,
        })
      }
    }
  }

  for (const day of week) {
    day.items = day.items.slice(0, 12)
  }

  return {
    week,
    weekUnscheduled: weekUnscheduled.slice(0, 24),
  }
}

async function collectMissionData() {
  const sources = []
  const columns = {
    planned: [],
    backlog: [],
    active: [],
    review: [],
    done: [],
  }
  const liveProcesses = []
  const cronJobs = []

  const reposWithPath = pickExistingRepoPaths(repoConfigs)

  const cronRes = await runCommand('planned:openclaw-cron', 'openclaw', ['cron', 'list', '--json'])
  sources.push(cronRes.source)

  if (cronRes.ok) {
    const parsed = safeJsonParse(cronRes.stdout, [])
    const jobs = Array.isArray(parsed) ? parsed : Array.isArray(parsed?.jobs) ? parsed.jobs : []

    cronJobs.push(...jobs)

    columns.planned = jobs.slice(0, 5).map((job) => {
      const label = job?.name || job?.id || job?.command || 'Cron job'
      const scheduleText = formatSchedule(job?.schedule)
      const schedule = scheduleText ? ` (${scheduleText})` : ''
      return `${label}${schedule}`
    })
  }

  for (const repo of repoConfigs) {
    const issuesRes = await runCommand(
      `backlog:gh-issues:${repo.slug}`,
      'gh',
      ['issue', 'list', '--repo', repo.slug, '--state', 'open', '--limit', '3', '--json', 'number,title,url'],
      { timeoutMs: 3000 },
    )
    sources.push(issuesRes.source)

    if (issuesRes.ok) {
      const issues = safeJsonParse(issuesRes.stdout, [])
      columns.backlog.push(...normalizeIssueItems(issues))
    }
  }

  columns.backlog = columns.backlog.slice(0, 6)

  const ghUserRes = await runCommand('review:gh-current-user', 'gh', ['api', 'user', '--jq', '.login'])
  sources.push(ghUserRes.source)
  const ghLogin = ghUserRes.ok ? ghUserRes.stdout.trim() : ''

  for (const repo of repoConfigs) {
    const prsRes = await runCommand(
      `review:gh-prs:${repo.slug}`,
      'gh',
      [
        'pr',
        'list',
        '--repo',
        repo.slug,
        '--state',
        'open',
        '--limit',
        '8',
        '--json',
        'number,title,url,author,reviewRequests,isDraft',
      ],
      { timeoutMs: 3000 },
    )
    sources.push(prsRes.source)

    if (prsRes.ok) {
      const prs = safeJsonParse(prsRes.stdout, [])
      const filtered = prs.filter((pr) => {
        if (pr?.isDraft) return false

        const requestedReviewers = Array.isArray(pr?.reviewRequests) ? pr.reviewRequests : []
        const reviewerLogins = requestedReviewers
          .map((reviewer) => reviewer?.login)
          .filter(Boolean)

        const hasReviewRequested = reviewerLogins.length > 0
        const requestedFromMe = ghLogin ? reviewerLogins.includes(ghLogin) : false
        const authoredByMe = ghLogin ? pr?.author?.login === ghLogin : false

        return hasReviewRequested || requestedFromMe || authoredByMe
      })

      columns.review.push(...normalizePrItems(filtered))
    }
  }

  columns.review = columns.review.slice(0, 6)

  const processRes = await runCommand('processes:ps', 'ps', ['-axo', 'pid,comm,args'], { timeoutMs: 2000 })
  sources.push(processRes.source)

  if (processRes.ok) {
    const lines = processRes.stdout
      .split('\n')
      .map((line) => line.trim())
      .filter(Boolean)
      .slice(1)

    const processRows = lines
      .map((line) => {
        const [pid, comm, ...rest] = line.split(/\s+/)
        const args = rest.join(' ')
        const commandName = comm?.includes('/') ? comm.split('/').pop() : comm

        return {
          pid,
          comm: commandName || comm,
          args,
          normalized: `${commandName || comm} ${args}`.toLowerCase(),
        }
      })
      .filter((row) => processKeywords.some((keyword) => row.normalized.includes(keyword)))

    const uniqueProcesses = []
    const seenKeys = new Set()

    for (const row of processRows) {
      const key = row.comm.toLowerCase()
      if (seenKeys.has(key)) continue
      seenKeys.add(key)
      uniqueProcesses.push(row)
      if (uniqueProcesses.length >= 8) break
    }

    liveProcesses.push(
      ...uniqueProcesses.map((proc) => ({
        name: proc.comm,
        state: 'Running',
        detail: `pid ${proc.pid}`,
      })),
    )

    columns.active.push(...uniqueProcesses.slice(0, 5).map((proc) => `${proc.comm} (pid ${proc.pid})`))
  }

  for (const repo of reposWithPath) {
    const branchRes = await runCommand(
      `active:branch:${repo.slug}`,
      'git',
      ['-C', repo.localPath, 'branch', '--show-current'],
      { timeoutMs: 1500 },
    )
    sources.push(branchRes.source)

    if (branchRes.ok && branchRes.stdout) {
      const branch = branchRes.stdout.trim()
      columns.active.push(`${repo.slug} branch: ${branch}`)
    }

    const commitRes = await runCommand(
      `done:commits:${repo.slug}`,
      'git',
      ['-C', repo.localPath, 'log', '-n', '3', '--pretty=format:%h|%s|%cr'],
      { timeoutMs: 2000 },
    )
    sources.push(commitRes.source)

    if (commitRes.ok) {
      const commits = commitRes.stdout.split('\n').filter(Boolean)
      columns.done.push(...normalizeCommitItems(repo.slug, commits))
    }
  }

  columns.active = Array.from(new Set(columns.active)).slice(0, 8)
  columns.done = columns.done.slice(0, 8)

  const { week, weekUnscheduled } = buildWeekOverview({ cronJobs, columns })

  const okSources = sources.filter((source) => source.ok).length
  const statusItems = [
    {
      name: 'Data sources',
      status: `${okSources}/${sources.length} healthy`,
      tone: okSources === sources.length ? 'text-emerald-300' : 'text-amber-300',
    },
    {
      name: 'Planned jobs',
      status: `${columns.planned.length} found`,
      tone: columns.planned.length > 0 ? 'text-sky-300' : 'text-zinc-400',
    },
    {
      name: 'Backlog issues',
      status: `${columns.backlog.length} open`,
      tone: columns.backlog.length > 0 ? 'text-violet-300' : 'text-zinc-400',
    },
    {
      name: 'Live processes',
      status: `${liveProcesses.length} running`,
      tone: liveProcesses.length > 0 ? 'text-emerald-300' : 'text-zinc-400',
    },
  ]

  return {
    statusItems,
    columns,
    week,
    weekUnscheduled,
    liveProcesses,
    fetchedAt: new Date().toISOString(),
    sources,
  }
}

function missionApiPlugin() {
  return {
    name: 'mission-api',
    configureServer(server) {
      server.middlewares.use('/api/mission', async (req, res) => {
        if (req.method !== 'GET') {
          res.statusCode = 405
          res.setHeader('Content-Type', 'application/json')
          res.end(JSON.stringify({ error: 'Method not allowed' }))
          return
        }

        try {
          const payload = await collectMissionData()
          res.statusCode = 200
          res.setHeader('Content-Type', 'application/json')
          res.end(JSON.stringify(payload))
        } catch (error) {
          res.statusCode = 200
          res.setHeader('Content-Type', 'application/json')
          res.end(
            JSON.stringify({
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
              fetchedAt: new Date().toISOString(),
              sources: [
                {
                  name: 'api:mission',
                  ok: false,
                  message: error?.message || 'mission data collection failed',
                },
              ],
            }),
          )
        }
      })
    },
  }
}

export default defineConfig({
  plugins: [react(), tailwindcss(), missionApiPlugin()],
})
