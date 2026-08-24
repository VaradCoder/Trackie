# Trackie — UI & Design System

## Design philosophy

One consistent visual language across every module. A new page (Gym, Calendar, Gaming…) is expected to be built entirely from the same cards, buttons, modals, and spacing rules as every other page — never a one-off style. Empty states are always honest (no fake placeholder data), and destructive actions always confirm before acting.

## Layout shell

Every authenticated page shares the same shell:

```
.app-shell
├── sidebar (nav grouped by section: Productivity / Lifestyle / Hobbies / Insights / Settings)
├── .main-wrap
    ├── header (page title, search, quick-add, notifications, dark-mode toggle, profile)
    └── .page-content#page-main   ← SPA nav swaps this on internal link clicks
```

- **Sidebar** — collapsible groups (Productivity, Lifestyle, Hobbies, Insights, Settings), active route highlighted, user card at top shows level + streak.
- **Header** — global search (Ctrl+K), quick-add, notifications bell, dark-mode toggle, profile avatar.
- **`renderPageHeader()`** — every page opens with a shared header component: icon + title + subtitle, with an `actions` slot for page-specific buttons (e.g. prev/Today/next on Calendar, "New Todo" on Todos).

## Design tokens (light/dark aware)

**Spacing scale** (`--sp-1`…`--sp-7`, 4px → 40px) — every margin/padding/gap in new UI is expected to snap to this scale rather than using arbitrary values.

```
--sp-1: 4px   --sp-2: 8px   --sp-3: 12px   --sp-4: 16px
--sp-5: 24px  --sp-6: 32px  --sp-7: 40px
```

**Radii**

```
--radius: 12px   --radius-sm: 6px   --radius-lg: 16px   --radius-xl: 20px
```

**Color tokens** — same variable names in both themes, different values:

| Token | Light | Dark |
|---|---|---|
| `--bg` | warm cream `#fbf3e7` | slate `#0f172a` |
| `--surface` | white | `#1e293b` |
| `--surface2` | — | `#334155` |
| `--border` | — | `#334155` |
| `--text` | — | `#f1f5f9` |
| `--muted` / `--subtle` | — | `#94a3b8` / `#64748b` |
| `--accent` / `--accent-h` | `#ef4444` / `#dc2626` (red) | same — red in both themes |
| `--ok` / `--warn` / `--info` | semantic greens/ambers/blues, consistent across themes |

Dark mode is a toggle in the header; the whole app — including every module built later — re-themes automatically because everything is token-driven, nothing is hard-coded.

## Core components (reused everywhere)

- **`.card` / `.card-body`** — the base content container
- **`.stat-card`** (via `renderStatCard()`) — icon + big number + label, used for every "at a glance" metric row (`.grid-stats`)
- **`renderEmptyState()`** — consistent "nothing here yet" illustration + copy, never fake data
- **`renderInsight()`** — small callout banner for a computed insight (e.g. "Most of your open tasks are medium priority")
- **`.modal-backdrop` / `.modal-box`** — the app's one modal system (header/title/body/footer), used for every dialog from "New Todo" to day-detail popovers
- **`.filter-tabs` / `.filter-tab`** — pill-style view/filter switchers (List/Timeline, Schedule/Board, All/Pending/Done…)
- **`Trackie.Toast`** — transient success/error notifications
- **`.btn` / `.btn-secondary` / `.btn-icon` / `.btn-sm`** — button system with consistent sizing and icon-only variants

## Interaction patterns

- **No full-page reloads for mutations.** Toggling a todo, logging a workout set, connecting an integration — all go through `Trackie.API.post()` and then call `refreshFragments([...])` to re-render just the affected DOM containers (stat cards, lists, badges) in place.
- **SPA-style navigation.** Internal links are intercepted by `SpaNav`, which swaps `#page-main` instead of doing a full browser navigation, while URLs and back/forward still work.
- **Motion.dev** powers page-transition and micro-interaction animation on top of this — subtle entrance/exit and state-change animation, not a full animation framework rebuild of the UI.
- **Mobile-first touch targets** — inputs, large buttons (`.wo-btn-lg` etc.) are sized up on small viewports; modals dock to the bottom sheet style on mobile.

## Module-specific visual patterns

- **Dashboard** — multi-column widget grid (`.d-card`), each module contributing a compact "glance" card (e.g. Weather, Now Playing, Today's workout).
- **Gym** — a "Fitness Dashboard" visual language (`.fit-*` classes): stat cards, a weekly-plan strip showing completed/planned/rest days, a conic-gradient daily-goal ring, live PR-detection banners during a workout session.
- **Calendar** — rebuilt in a **Microsoft Planner** style: a Schedule view (month grid with compact colored task chips per day, click-to-open day-detail modal) and a Board view (Kanban-style urgency columns: Overdue / Today / This Week / Later / Done), plus a month-level progress stat row.
- **Gaming** — Steam-style game cards with cover art (`header.jpg` via Steam CDN, with graceful `onerror` fallback).

## Motion system

Trackie already runs a single small motion system (`assets/css/app.css`) — every module reuses these tokens rather than inventing its own timings:

```
--dur-fast: 150ms      quick state changes (button press, hover)
--dur-base: 200ms      default transitions
--dur-slow: 250ms      progress bars, larger surfaces (never exceeded — no 800ms tweens)
--ease-out:    cubic-bezier(.16,1,.3,1)      entrances
--ease-in-out: cubic-bezier(.65,0,.35,1)     state toggles
```

What it drives, end to end:

- **Page navigation** — `SpaNav` swaps `#page-main` wrapped in the native View Transitions API (`document.startViewTransition`) where supported: old page fades out, new page fades in + rises 8px, ~220ms, `cubic-bezier(.22,1,.36,1)`. A `@view-transition` rule handles full-document navigations (login/logout) the same way. Fully degrades to an instant swap where View Transitions isn't supported or `prefers-reduced-motion: reduce` is set.
- **Buttons/tabs/nav items** — `:active` press to `scale(.96)` (`.btn`, `.filter-tab`, `.bottom-nav-fab`).
- **Cards** — `.stat-card`/`.habit-card`/`.card` lift `translateY(-2px)` + shadow increase on hover.
- **Modals** — `.modal-backdrop` fades in (`.15s`); `.modal-box` fades + scales up from `.96`.
- **Bottom sheets** (mobile modals) — slide up from the bottom (`sheet-up` keyframe) instead of the desktop center-scale.
- **Toasts** — slide in from the right + fade (`toast-in`), slide back out + fade on dismiss.
- **Achievement unlocks** — a distinct toast variant (`Trackie.Toast.achievement()` / `.toast-achievement`): gold gradient, trophy/emoji badge with a pop-in, a soft one-shot glow ring. Multiple simultaneous unlocks stagger ~450ms apart (`Trackie.showAchievementToasts()`) so they read as a sequence, not a pile-up.
- **Ripple** (`Ripple` module) — Material-style press ripple on tap targets that opt in.
- **Skeletons** (`PageSkeleton`, `.skeleton` shimmer) — structural placeholders shaped like the real content (stat-card outlines, list-row bars), not a spinner — swapped for real content once the fragment loads.
- **Sidebar** — width transitions (`--dur-slow`) when collapsing/expanding groups.

Every animated rule has a `prefers-reduced-motion: reduce` fallback that disables or shortens the effect — this is checked, not assumed, throughout `app.css`.

## Command palette

`Ctrl+K` opens a combined search + command palette (`Search` module, `includes/header.php`), not just a search box:
- **Action commands** — "New todo", "Toggle dark mode" (extendable — add entries to `ACTION_COMMANDS`/`ACTIONS` in `assets/js/app.js`).
- **Navigation commands** — read live from the rendered sidebar (`navCommands()`), so the palette can never drift out of sync with the nav — new modules, hobby pages, everything shows up automatically.
- Full keyboard support: type to filter, arrow keys to move, Enter to run/navigate, Esc to close.

## Mobile app shell

Below the desktop sidebar breakpoint, the header renders a real bottom navigation bar (`.bottom-nav`, driven by `navPrimary()` — never hand-duplicated) with a center floating-action button for quick-add, plus 2 nav items on each side. Active route highlighting and SPA nav both work identically to the desktop sidebar.

## Optimistic UI pattern

The established pattern (see `toggleTodo()` in `pages/todos.php`) for any mutating action:
1. Update the DOM immediately (checkbox state, strikethrough, etc.) — before the network call resolves.
2. Fire `Trackie.API.post()`.
3. On success: refresh the relevant stat fragment via `refreshFragments()`, show a success toast (+ an achievement toast via `Trackie.showAchievementToasts()` if the response includes newly unlocked achievements).
4. On failure (network error or `res.success === false`): revert the optimistic DOM change and show an error toast.

New mutating actions across the app should follow this same shape rather than waiting on the server round-trip before updating the UI.

## Accessibility & robustness notes

- Priority/urgency is always communicated with both color *and* a text label (never color alone) — e.g. High/Medium/Low badges, not just colored dots.
- Interactive day cells and cards use `role="button"`/`tabindex` where they're not native `<button>`/`<a>` elements.
- Every destructive action (delete todo, disconnect integration) goes through `Trackie.confirmDialog()` before executing.
