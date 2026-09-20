# Life Tempo

A retirement-engagement tracker: log activities across health, spiritual life,
music, shared life with a spouse, learning, service, and paid work, then see
whether things are staying in balance over time — without turning retirement
into a job.

Full philosophy, conceptual model, MySQL spec, and phased roadmap:
[`docs/life-tempo-spec.md`](docs/life-tempo-spec.md).

## Status

**Phase 6 — Planning, Seasons, and Calendar-Friendly Behavior** (see spec
section 146). Phases 1-5 (login; activity logging; goals/cadence/Dashboard;
people/shared life/learning; the Engagement score and heat map — the
spec's first major product milestone) are done. Phase 6 adds:

- **Plan** (new nav item) — add upcoming events (title, optional activity/
  location, date/time); **Complete** one and, if it has an activity, its
  ActivityLog entry is created automatically — completing a plan *is*
  logging it, no double entry. Also **Cancel**, **Edit**, **Delete**, and
  an "Add to Google Calendar" link per event.
- **Today → Coming Up** — the next few planned events, right where you
  already check in daily.
- **Manage → Seasons** — recurring date ranges (e.g. "Concert Band Season"
  01-01 to 09-30); link a goal to one or more seasons and it only counts
  toward Dashboard/Engagement while today falls in season.
- **Plan → Mark a Date Range** — bulk-mark a trip/illness as one day type
  across several days at once, instead of clicking through each day.
- **Navigate links** — Locations (Manage) and any location shown on
  Today/History/Plan now link out to Google Maps.

Templates (spec section 29/51) were skipped — Activity's existing Quick Log
flag already covers one-tap logging, and a separate ExceptionPeriod table
was folded into the existing per-day DayStatus mechanism rather than
maintaining two overlapping day-exclusion concepts (see `api/schema.sql`
Phase 6 comments for the reasoning).

## Next

Phase 7 (Travel, Contract Work, and Advanced Features) is the last phase
in the spec's roadmap — see spec section 146. As always (147.2), worth
using Phase 6 for a while first: is Plan actually where upcoming
commitments get tracked, or does it go unused? Do Seasons behave as
expected once one starts/ends?

## Stack

Same pattern as the other MyDataWorld apps (T-Minus, Choir Connect, Reading
List): static HTML/CSS/vanilla JS front end, a single `api/api.php`
(mysqli, bearer-token sessions) hosted on seniorfamily.org, GitHub Pages for
the front end. See [`SETUP.md`](SETUP.md) for deployment steps.
