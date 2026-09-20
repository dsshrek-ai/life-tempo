# Life Tempo

A retirement-engagement tracker: log activities across health, spiritual life,
music, shared life with a spouse, learning, service, and paid work, then see
whether things are staying in balance over time — without turning retirement
into a job.

Full philosophy, conceptual model, MySQL spec, and phased roadmap:
[`docs/life-tempo-spec.md`](docs/life-tempo-spec.md).

## Status

**Phase 7 — Travel, Contract Work, and CSV Export** (see spec section 146)
— the **last phase** in the spec's roadmap. Phases 1-6 (login; activity
logging; goals/cadence/Dashboard; people/shared life/learning; the
Engagement score and heat map; Plan/Seasons/calendar links) are done.
Phase 7 was explicitly the most optional phase (the spec says "build as
needed"), so it was scoped to what was actually asked for:

- **Trips** (new nav item) — plan a trip (name, purpose, dates, estimated
  miles/cost), add ordered **Stops** (location + planned date) and
  **Expenses** (date/type/amount/description); actual cost is always the
  sum of expenses, never a second manually-entered number that could drift
  from it.
- **Manage → Clients** — attribute a Billable log entry to a client on
  Today/History.
- **Reports** (new nav item) — a Billable Report (hours/amount by client
  for a date range) plus CSV export for it and for Trips.
- **History → Download CSV** — export the currently filtered activity log.

Skipped, per the spec's own "build as needed" guidance and your choice when
asked: WorkProject/Invoice (a Client plus the existing Billable flag is
enough for reporting), full Google Calendar sync (the Phase 6 "Add to
Google Calendar" quick-add link covers most of the practical need), a
shared partner account, and notifications.

## Feature toggles

Not every user of this app wants Travel or Billable/Invoice tracking. On
**Manage → Settings**, either can be turned off — this hides the related nav
link(s) and form fields (Trips; Reports' Billable Report/Trips Export;
Manage's Clients section; the Client field on Today/History) without
deleting anything already entered, and turning a toggle back on brings it
right back. This is UI-only, not an access boundary (a personal/family app
doesn't need one user blocked from a URL, just an uncluttered nav for the
areas they don't use) — see `api/schema.sql`'s `lt_user_preferences` comment.

## Next

There is no Phase 8 in the spec — Phase 7 is the end of the documented
roadmap. From here it's real usage (147.2): does Trips get used for an
actual temple trip? Does the Billable Report match what you'd expect?
Anything from here on should come from something that's actually come up,
not from the spec.

## Stack

Same pattern as the other MyDataWorld apps (T-Minus, Choir Connect, Reading
List): static HTML/CSS/vanilla JS front end, a single `api/api.php`
(mysqli, bearer-token sessions) hosted on seniorfamily.org, GitHub Pages for
the front end. See [`SETUP.md`](SETUP.md) for deployment steps.
