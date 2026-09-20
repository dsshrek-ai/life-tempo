# Life Tempo

A retirement-engagement tracker: log activities across health, spiritual life,
music, shared life with a spouse, learning, service, and paid work, then see
whether things are staying in balance over time — without turning retirement
into a job.

Full philosophy, conceptual model, MySQL spec, and phased roadmap:
[`docs/life-tempo-spec.md`](docs/life-tempo-spec.md).

## Status

**Phase 5 — Engagement Scoring and Heat Maps** (see spec section 146) —
the spec calls this the **first major product milestone**: once it's
working, the app fully answers its central question, "Am I living the
retirement I intended to live?" Phases 1-4 (login; activity logging; goals/
cadence/Dashboard; people/shared life/learning) are done. Phase 5 adds:

- **Manage → Goals → Weight** — how much a goal counts toward the overall
  score (default 1; higher counts more, lower counts less)
- **Engagement** (new nav item) — an overall score for This Week, Rolling 4
  Weeks, This Month, This Quarter, and This Year, each a weighted average of
  every active goal, capped per goal at 100% so no goal can inflate the
  score by overachieving. Every score has a "Breakdown" showing exactly
  which goals contributed, their actual/expected, and their weight —
  nothing is a black box.
- **Engagement → Weekly Heat Map** — the last 8 weeks, one row each, colored
  by score (green 70%+, amber 50-69%, red below 50%) so patterns over time
  are visible at a glance, not just a single current number.

## Next

Phase 6 (Planning, Seasons, and Calendar-Friendly Behavior) and Phase 7
(Travel, Contract Work, and Advanced Features) remain — see spec section 146.
Per spec section 147.2, it's worth actually living with Phase 5 a while
before building further: does the engagement score feel meaningful? Is any
goal's weighting off? Does the heat map reveal anything surprising?

## Stack

Same pattern as the other MyDataWorld apps (T-Minus, Choir Connect, Reading
List): static HTML/CSS/vanilla JS front end, a single `api/api.php`
(mysqli, bearer-token sessions) hosted on seniorfamily.org, GitHub Pages for
the front end. See [`SETUP.md`](SETUP.md) for deployment steps.
