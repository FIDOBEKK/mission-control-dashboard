# Mission Control Dashboard (local mock)

Static, local-only dashboard shell inspired by Linear.

## Stack

- Vite
- React
- Tailwind CSS (via `@tailwindcss/vite`)

## Features implemented

- Dark mode default UI
- Left sidebar navigation
- Top bar with search + quick actions
- Main grid with empty module cards:
  - Today
  - Priorities
  - Projects
  - Signals
  - Decisions
- Right rail placeholders for Activity and Notes
- Status strip with mock system states:
  - Calendar sync
  - GitHub sync
  - Transcription pipeline
  - Local AI status

Everything is static/mock for now.

## Run locally

```bash
cd /Users/andersiglebekk/Documents/mission-control-dashboard
npm install
npm run dev
```

Then open the local Vite URL shown in terminal (usually `http://localhost:5173`).

## Build

```bash
npm run build
```
