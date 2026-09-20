# Life Tempo

A retirement-engagement tracker: log activities across health, spiritual life,
music, shared life with a spouse, learning, service, and paid work, then see
whether things are staying in balance over time — without turning retirement
into a job.

Full philosophy, conceptual model, MySQL spec, and phased roadmap:
[`docs/life-tempo-spec.md`](docs/life-tempo-spec.md).

## Status

**Phase 2 — Core Activity Logging** (see spec section 146). Phase 1 (login via
My Apps Hub / MyDataWorld SSO, tenant-safe API pattern) is done. Phase 2 adds:

- **Manage** — Categories, Locations, and Activities (name, category, typical
  duration, Productive/Billable-eligible flags, default location, Quick Log)
- **Today** — Quick Log buttons for favorite activities, a full Log Activity
  form, and today's entries
- **History** — activity log filtered by activity and date range, with edit
  and delete

Goals, cadence, and the weekly dashboard are Phase 3.

## Stack

Same pattern as the other MyDataWorld apps (T-Minus, Choir Connect, Reading
List): static HTML/CSS/vanilla JS front end, a single `api/api.php`
(mysqli, bearer-token sessions) hosted on seniorfamily.org, GitHub Pages for
the front end. See [`SETUP.md`](SETUP.md) for deployment steps.
