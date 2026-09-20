# Life Tempo

A retirement-engagement tracker: log activities across health, spiritual life,
music, shared life with a spouse, learning, service, and paid work, then see
whether things are staying in balance over time — without turning retirement
into a job.

Full philosophy, conceptual model, MySQL spec, and phased roadmap:
[`docs/life-tempo-spec.md`](docs/life-tempo-spec.md).

## Status

**Phase 4 — People, Shared Life, and Learning** (see spec section 146).
Phases 1-3 (login/tenant-safe API; Categories/Locations/Activities logging;
Goals/cadence/weekly Dashboard) are done. Phase 4 adds:

- **Manage → People / Tags / Learning Projects** — new master lists
- **Today/History log form** — "With" (people), "Shared Life" flag, Tags,
  and an optional Learning Project + Learn/Apply mode per entry
- **Dashboard → Shared Life** — a count of Shared Life entries this week
  and this month

Phase 5 (engagement scoring and heat maps) is next — the spec (section 146)
calls it the first major product milestone.

## Stack

Same pattern as the other MyDataWorld apps (T-Minus, Choir Connect, Reading
List): static HTML/CSS/vanilla JS front end, a single `api/api.php`
(mysqli, bearer-token sessions) hosted on seniorfamily.org, GitHub Pages for
the front end. See [`SETUP.md`](SETUP.md) for deployment steps.
