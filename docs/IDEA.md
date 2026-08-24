# Trackie — The Idea

## What it is

Trackie is a **single-user, all-in-one personal productivity and life-tracking PWA**. Instead of juggling a to-do app, a habit tracker, a budgeting app, a gym log, a reading list, and a media dashboard separately, Trackie puts all of it behind one login, one design system, and one gamification layer.

The guiding idea: **your whole life, one dashboard.** Productivity (todos, habits, goals, routines, calendar, study, focus, reminders) sits alongside lifestyle tracking (finance, gym, cooking, gardening, sports, meditation) and hobby/media tracking (gaming, music, art, photography, writing, library/reading), all reporting into shared analytics, a streak/XP system, and achievements — so effort in any one area visibly contributes to a single sense of progress.

## Why it exists

- **Consolidation over fragmentation.** Most people spread this data across 8–10 different apps with no shared identity, no shared streaks, and no single "how am I doing overall" view. Trackie answers that one question.
- **Gamified motivation.** A todo app alone doesn't feel rewarding. Trackie wraps everything in XP, levels, streaks, and achievements so consistency (not just task completion) is the thing being rewarded.
- **Own your data.** It's self-hosted (runs on the user's own XAMPP/InfinityFree stack), not a SaaS product with a subscription — the trade-off is deliberately "I maintain it" in exchange for full data ownership and no recurring cost.
- **Integrate, don't recreate.** Where a good free API already exists (Steam for games, Spotify for music, Open-Meteo for weather, GitHub for coding activity), Trackie connects to it rather than asking the user to manually log things a third party already tracks.

## Core product principles

1. **One design system, everywhere.** Every module (gym, calendar, finance, gaming…) reuses the same cards, buttons, modals, spacing tokens, and color tokens — new features are expected to look like they were always part of the app, not bolted on.
2. **No fake data, ever.** If an integration isn't connected or a metric can't be computed, the UI shows an honest empty state — never a placeholder number pretending to be real.
3. **Reuse existing systems.** New features plug into the existing XP/streak/achievement engine, the existing auth and API conventions, and the existing DB migration pattern — parallel/duplicate systems are avoided.
4. **Fast and app-like.** As a PWA with a manifest, offline-aware API calls, and SPA-style navigation, Trackie is meant to feel closer to a native app than a stack of server-rendered pages.
5. **Ask, don't guess.** Wherever data is ambiguous (e.g. matching an exercise to a downloaded video), Trackie prompts the user to confirm rather than silently guessing.

## Who it's for

Right now: a single user (the app's builder), self-hosted, with all modules tailored to one person's actual routines (gym plans, study plans, finance categories, hobby list). It is *not* currently built for multi-tenant/public use — auth exists, but the product philosophy is "my own life OS," not "a SaaS for everyone."
