# Life Tempo

A retirement-engagement tracker: log activities across health, spiritual life,
music, shared life with a spouse, learning, service, and paid work, then see
whether things are staying in balance over time — without turning retirement
into a job.

Full philosophy, conceptual model, MySQL spec, and phased roadmap:
[`docs/life-tempo-spec.md`](docs/life-tempo-spec.md).

## Status

**Phase 3 — Goals, Cadence, and Weekly Progress** (see spec section 146).
Phases 1 (login/tenant-safe API) and 2 (Categories, Locations, Activities,
Today/History activity logging) are done. Phase 3 adds:

- **Manage → Goals** — name, type (Minimum/Target/Maximum/TrackOnly), cadence
  (Daily/Weekly/Monthly), target value, and which activities count toward it
- **Dashboard** — current-week progress for Daily/Weekly goals and
  current-month progress for Monthly goals, with a status badge and bar
- **Today → day type** — mark today Home/Local Outing/Travel/Vacation/Sick/
  Special Event so Daily-cadence goals don't expect activity on days that
  aren't "home days"

Phase 4 (people, shared life, learning) is next.

## Stack

Same pattern as the other MyDataWorld apps (T-Minus, Choir Connect, Reading
List): static HTML/CSS/vanilla JS front end, a single `api/api.php`
(mysqli, bearer-token sessions) hosted on seniorfamily.org, GitHub Pages for
the front end. See [`SETUP.md`](SETUP.md) for deployment steps.
