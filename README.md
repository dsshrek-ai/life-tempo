# Life Tempo

A retirement-engagement tracker: log activities across health, spiritual life,
music, shared life with a spouse, learning, service, and paid work, then see
whether things are staying in balance over time — without turning retirement
into a job.

Full philosophy, conceptual model, MySQL spec, and phased roadmap:
[`docs/life-tempo-spec.md`](docs/life-tempo-spec.md).

## Status

**Phase 1 — Foundation and Security** (see spec section 146). Login via My
Apps Hub / MyDataWorld SSO, a tenant-safe API pattern, and a bare app shell.
No activity logging yet — that's Phase 2.

## Stack

Same pattern as the other MyDataWorld apps (T-Minus, Choir Connect, Reading
List): static HTML/CSS/vanilla JS front end, a single `api/api.php`
(mysqli, bearer-token sessions) hosted on seniorfamily.org, GitHub Pages for
the front end. See [`SETUP.md`](SETUP.md) for deployment steps.
