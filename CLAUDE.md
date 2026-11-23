# iFood Orders

Laravel monolith that consumes the iFood Merchant API (Order module) and shows orders in a small CRM-style panel (board + list). Built as a technical test for a PHP back-end role: the point is to show how failures, duplicated events and out-of-order events are handled. It is **not** a full POS.

## Stack

- Laravel 13, PHP 8.5, PostgreSQL 18, Redis 8, Horizon, scheduler
- Inertia v3 + React 19 + TypeScript + Tailwind v4 + shadcn/ui (`resources/js`)
- Pest, PHPStan/Larastan (level 9), Pint, GitHub Actions
- Everything runs in Docker and is driven by the `Makefile`

## Commands

Always go through `make`, since the local PHP has no `pdo_pgsql`/`redis`.

| Command | What it does |
|---|---|
| `make setup` | First run: `.env`, image, dependencies, key, migrations, assets, containers |
| `make up` / `make dev` / `make down` | Start the stack / start it with the Vite dev server / stop it |
| `make ci` | `lint` + `stan` + `test`, the same checks as CI. Run it before declaring work done |
| `make test args="--filter=Polling"` | Pest against the `ifood_orders_test` database |
| `make fix` | Pint |
| `make demo` | Synthetic demo orders (no iFood credentials needed) |
| `make reload` | Restart Horizon workers and the scheduler after code changes (workers keep old code in memory) |
| `make shell` / `make logs s=horizon` | Debugging |
| `npm run typecheck` / `npm run build` | Front-end checks (also in CI) |

## Architecture

```
app/Ifood/Client/      HTTP client, token cache, 429 handling, correlation id
app/Ifood/Enums/       EventCode, OrderStatus
app/Ifood/Orders/      OrderStateMachine, applying events to orders
app/Ifood/Actions/     OrderActionService: request, resolve by event, expire
app/Models/            IfoodEvent, Order, OrderTransition, OrderAction
app/Jobs/              ProcessIfoodEventJob, FetchOrderDetailsJob, SendOrderActionJob
app/Console/Commands/  ifood:poll, ifood:retry, ifood:expire-actions
app/Http/              OrderController (panel), OrderActionController, resources
database/seeders/      DemoOrdersSeeder (`make demo`), synthetic orders through the real job
resources/js/pages/    Inertia pages (orders board/list)
resources/js/components/orders/  ticket, board, list, sheet, actions, deadline bar
tests/Fixtures/ifood/  JSON fixtures for Http::fake
```

Intake flow (`app/Ifood/Events/EventIngestor.php`): `ifood:poll` (scheduler, every 30s) → `INSERT … ON CONFLICT (event_id) DO NOTHING RETURNING` → one `ProcessIfoodEventJob` per new row (queue `ifood-events`, oldest first) → ack every received id. `ifood:retry` (every minute) re-dispatches failed events after backoff and `received` events stuck for over 2 minutes.

Tables: `ifood_events` (raw events, `event_id` unique), `orders` (current state, `ifood_order_id` unique), `order_transitions` (audit trail of applied/ignored transitions), `order_actions` (merchant actions waiting for confirmation by event).

## Domain rules (do not break these)

0. **Real endpoints** (confirmed in `docs/discovery.md`, which beats the public docs): polling `GET /order/v1.0/events:polling` (204 when empty), ack `POST /order/v1.0/events/acknowledgment` with `[{"id": "…"}]`, every action is `POST`, cancellation body is `{"reason", "cancellationCode"}`.
1. **Persist, then ack.** Polling events are inserted with `insertOrIgnore` on `event_id`. Only after the transaction commits are *all* received IDs acknowledged, duplicates included, because they are already stored. An event that was never stored must never be acked.
2. **One job per new event**, dispatched right after the insert, oldest first. Events for the same order are serialized: a lock on `order_id` plus `lockForUpdate` on the order row.
3. **Out-of-order events** (`app/Ifood/Orders/OrderStateMachine.php`, pure and unit tested). Statuses are monotonic, so the status is decided by **rank only**: the highest status seen wins, whatever the arrival order. Skips are valid (DSP straight from CONFIRMED was accepted by the real API). `CONCLUDED`/`CANCELLED` are terminal: a CFM after CAN never reopens an order (`order_terminal`). A lower or equal rank is `not_forward`. CAN applies from any non-terminal status. `createdAt` still drives the timeline, `placed_at` and the 8-minute deadline, even when the PLC arrives last. Every event writes one `order_transitions` row (unique per event) with `applied` and `reason`; ignored events also get `ifood_events.status = ignored` with the same reason.
4. **CAR/CARF** do not change the order status (`no_status_change`, event still `processed`). A CAR/CARF for an order not seen yet waits (job released) for the status event. They resolve the pending `order_actions` row. CAN is applied even without a prior CAR (observed in practice). Unknown codes (e.g. DDCR) are stored and acked but never block the queue.
5. **Order details** (`FetchOrderDetailsJob`, unique per order, dispatched when an order is first seen) may return 404 right after PLC: released with backoff 5s, 10s, 20s… up to 5 min, for 10 minutes, then `details_error`.
6. **Actions return 202.** `order_actions`: pending → sent (202) → confirmed (matching event, or the order passed the target status) | failed (4xx, CARF, order cancelled first) | expired (`ifood:expire-actions`, 2 min). A CAR does not settle a cancellation (a CARF may follow); the CAN does. A partial unique index allows one open action per order. `OrderActionType::allowedFor()` is checked before calling iFood, because iFood accepts invalid actions with 202 and drops them silently.
7. **Token**: cached until shortly before it expires, never requested per call. **429**: respect `X-RateLimit-Reset`.
8. **Timestamps keep milliseconds**: columns are `timestampTz(…, 3)` and models use `$dateFormat = 'Y-m-d H:i:s.vP'` (CAR→CAN can be ~150ms apart). Keep both when adding tables.

Anything about the iFood API that was confirmed or contradicted with real credentials goes in `docs/discovery.md`. Treat that file as the source of truth over the public docs.

## Conventions

- `declare(strict_types=1)` everywhere (enforced by Pint and an arch test).
- No `env()` outside `config/`. iFood settings live in `config/ifood.php`.
- Backed enums for event codes and statuses. Never compare raw strings.
- Logs go through the `ifood` channel (JSON lines in `storage/logs/ifood-*.log`). `App\Support\CorrelationId` keeps the id in Laravel `Context`, so it reaches every log record and travels with queued jobs.
- Do not add `env_file` to `compose.yaml`: Laravel reads `.env` itself, and injected variables would override the forced test values in `phpunit.xml`.
- Tests: Pest, `Http::fake()` with fixtures from `tests/Fixtures/ifood`, `Http::preventStrayRequests()`. Feature tests run on Postgres, not SQLite, because uniqueness and locking behaviour matter here.
- Panel design: kitchen-rail palette (`--basil`, `--saffron`, `--chili` on `--paper`), Atkinson Hyperlegible Next, tabular numbers. The one bold element is the draining confirmation-deadline bar; keep everything else quiet (no shadows, no all-caps labels). UI copy in PT-BR, sentence case.
- Front-end: shadcn components in `resources/js/components/ui` are generated; customise them by wrapping, not by editing them, unless that is unavoidable.

## Rules

- **Secrets**: credentials only in `.env`, which is git-ignored. Never put a Client ID, Client Secret, token or real merchant data in code, logs, fixtures, docs or commits.
- **Language**: code, identifiers, comments and commits in English. README, PR descriptions and conversation with the author in Brazilian Portuguese.
- **Commits**: Conventional Commits, short, one scope per commit. No `Co-Authored-By` trailer and no "Generated with Claude Code" line in commits or PRs.
- **Never** commit, push or create the GitHub repository unless explicitly asked.
- `Prompt.md` is the local starting brief. It is git-ignored and must stay out of the repo.

## Roadmap

- [x] 0. Discovery with real credentials → `docs/discovery.md`
- [x] 1. Skeleton: Docker, Makefile, Pest, PHPStan, Pint, CI, Inertia/React/shadcn
- [x] 2. iFood client: token cache, 429, correlation-id logs
- [x] 3. Event intake: polling every 30s, unique event id, ack after persisting, one job per event
- [x] 4. Orders and state machine, details fetch with 404 backoff
- [x] Panel: board (columns by status) + list, auto-refresh, detail sheet with event timeline
- [x] 5. Actions (confirm, start preparation, ready, dispatch, cancel), CARF, 8-minute deadline alert
- [x] 6. Test scenarios: duplicate, out of order, 404, CARF, 429, late event
- [x] 7. README (PT-BR) with decisions, how to run, screenshots (GIF of real orders pending)
