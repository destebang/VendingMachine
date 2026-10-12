# Vending Machine

A vending machine modelled with **DDD + CQRS + Event Sourcing** on top of
**Symfony components**, with a **MongoDB**-backed write model and a
**Redis**-backed read model.

It accepts 0.05 / 0.10 / 0.25 / 1 coins, sells `WATER` (0.65), `JUICE`
(1.00) and `SODA` (1.50), supports "return coin", and lets a service
person restock items and top up the change reserve.

```
1, 0.25, 0.25, GET-SODA   -> SODA
0.10, 0.10, RETURN-COIN   -> 0.10, 0.10
1, GET-WATER              -> WATER, 0.25, 0.10
```

## Requirements

* Docker & Docker Compose (everything runs in containers — no local PHP/Mongo/Redis needed)
* To run things outside Docker instead: PHP ≥ 8.3 with the `mongodb` extension, Composer, a reachable MongoDB 7 and Redis 7

## Running it

```bash
docker compose up -d --build
docker compose exec app php bin/console app:infra:init        # one-off: create the Mongo index
```

Service the machine (a fresh machine has no stock/change until this runs):

```bash
docker compose exec app php bin/console app:machine:service \
  --item=WATER:10 --item=JUICE:10 --item=SODA:10 \
  --change=0.25:40 --change=0.10:40 --change=0.05:40
```

Operate it:

```bash
docker compose exec app php bin/console app:machine:insert-coin 0.25
docker compose exec app php bin/console app:machine:insert-coin 1
docker compose exec app php bin/console app:machine:select-product SODA
docker compose exec app php bin/console app:machine:return-coin
docker compose exec app php bin/console app:machine:state
```

Or replay a whole sequence exactly as written in the spec's examples:

```bash
docker compose exec app php bin/console app:machine:simulate "1, 0.25, 0.25, GET-SODA"
# -> SODA
docker compose exec app php bin/console app:machine:simulate "0.10, 0.10, RETURN-COIN"
# -> 0.10, 0.10
docker compose exec app php bin/console app:machine:simulate "1, GET-WATER"
# -> WATER, 0.25, 0.10
```

All commands accept `--machine=<id>` (defaults to `main`) so several
independent machines can be operated against the same deployment.

## Running the tests

```bash
docker compose exec app vendor/bin/phpunit --testsuite=unit          # fast, no infra
docker compose exec app env APP_ENV=test MONGO_DB=vending_test REDIS_URL=redis://redis:6379/1 \
  vendor/bin/phpunit --testsuite=integration
docker compose exec app env APP_ENV=test MONGO_DB=vending_test REDIS_URL=redis://redis:6379/1 \
  vendor/bin/phpunit --testsuite=functional
# or simply, for everything:
docker compose exec app env APP_ENV=test MONGO_DB=vending_test REDIS_URL=redis://redis:6379/1 \
  vendor/bin/phpunit
```

* **Unit** (`tests/Unit`) — the domain aggregate, value objects and
  application handlers, exercised in isolation with in-memory fakes.
  This is where every business rule in the spec is pinned down.
* **Integration** (`tests/Integration`) — the MongoDB event store and
  Redis read-model repository against real instances.
* **Functional** (`tests/Functional`) — boots the real Symfony kernel
  and replays the specification's three examples through the actual
  command/query buses, MongoDB and Redis.

## Architecture

```
src/VendingMachine/
├── Domain/           # Pure business logic. No framework, no I/O.
│   ├── Model/        # VendingMachine aggregate, value objects (Coin, Sku, Money...)
│   ├── Event/        # What happened: CoinInserted, PurchaseCompleted, ...
│   └── Exception/
├── Application/      # Use cases: orchestrates the domain, talks only to ports.
│   ├── Command/      # Write side — one folder per use case (Command + Handler [+ Result])
│   ├── Query/         # Read side — GetMachineState
│   ├── ReadModel/     # Read-model port + DTOs (MachineStateView, ItemView)
│   ├── Projection/    # Builds the read model by reacting to domain events
│   └── Shared/        # DomainEventPublisher (thin wrapper over the event bus)
└── Infrastructure/   # Adapters: the only place that knows about Mongo/Redis/Console.
    ├── Persistence/Mongo/   # Event store + event-sourced repository
    ├── ReadModel/Redis/     # Redis-backed read model repository
    └── Console/              # CLI commands (the only driving adapter today)
```

This is a hexagonal/ports-and-adapters layout: the `Domain` and
`Application` layers depend on **interfaces** only
(`VendingMachineRepository`, `MachineStateReadModelRepository`); the
`Infrastructure` layer provides the Mongo/Redis implementations, wired
together in `config/services.yaml`. Swapping MongoDB or Redis for
something else — or adding an HTTP API next to the console — never
touches the domain.

### CQRS

Three separate Symfony Messenger buses, configured in
`config/messenger.yaml`:

* **`command.bus`** — mutates the vending machine (`InsertCoin`,
  `ReturnCoin`, `SelectProduct`, `ServiceMachine`). Every command has
  exactly one handler and returns a result DTO describing what
  happened (there is no generic "success" boolean: a purchase can be
  dispensed, or rejected for one of several distinct reasons).
* **`query.bus`** — reads state (`GetMachineState`). Query handlers
  never touch the write model; they only read the Redis projection.
* **`event.bus`** — fans out domain events raised by the aggregate to
  projectors. Today there is a single subscriber
  (`MachineStateProjector`), but this is the seam where you'd plug in
  analytics, alerting on low stock, etc. without touching the write
  path.

### Event Sourcing on MongoDB (write model)

The `VendingMachine` aggregate doesn't store its current state
directly — it stores the sequence of `DomainEvent`s that produced it
(`CoinInserted`, `CoinsReturned`, `PurchaseCompleted`,
`PurchaseRejected`, `MachineServiced`) in a single append-only
collection, one document per event, keyed by `(streamId, version)`.
Loading the aggregate replays its full history.

Why event sourcing for a kata this small? Two reasons that matter in
production:

1. **A free, complete audit trail.** "Why does the change reserve look
   like this?" or "did we actually run out of change for machine
   #42 three times last week?" are just queries against the event
   store.
2. **The read model is disposable.** Redis projections can be dropped
   and rebuilt from scratch at any time by replaying MongoDB — the
   `MachineStateProjector` depends only on the read-model port, so it
   doesn't care whether it's reacting to a live event or a replayed
   one.

Optimistic concurrency is enforced by a unique index on
`(streamId, version)`: two concurrent writers both believing they
started from version N will have one `insertMany` fail with a
duplicate key error, surfaced as `ConcurrentModificationException`.

### Redis (read model)

Each machine's current state (stock, change reserve, money currently
inserted) is projected into a single JSON document per machine,
updated synchronously whenever a domain event is published. It is a
pure cache of "what does querying this machine look like right now" —
no business decisions are made here, only reflected.

## Business rules worth calling out

* **Money is always integer cents.** Floats never appear in the
  domain — `Coin` is a backed enum of cents, every calculation is
  integer arithmetic.
* **Change is only ever 0.05 / 0.10 / 0.25.** The spec's response
  contract never lists $1 as a valid response, so `$1` coins are
  accepted but never recirculated as change — they are simply
  retained as revenue. `Coin::changeDenominations()` encodes this.
* **"Return coin" returns the literal coins inserted**, not an
  equivalent value reconstructed from the change reserve — matching
  the spec's example (`0.10, 0.10 in -> 0.10, 0.10 out`, not `0.20`).
* **Insufficient funds doesn't eject the money.** `GET-*` on a machine
  that hasn't received enough money yet is rejected, but the inserted
  coins stay inserted so the customer can top up and try again (or
  press return coin).
* **"Exact change only."** If a purchase is otherwise valid but the
  machine cannot make the required change from its reserve, the sale
  is rejected and the full amount inserted is refunded automatically
  — mirroring how real machines behave, rather than silently keeping
  the customer's money.
* **Change-making is a bounded search, not a naive greedy algorithm.**
  With a *limited* supply of coins, "give the biggest coin first" can
  fail where a solution still exists (see the docblock and regression
  test on `ChangeInventory::tryWithdraw()` for the worked
  counter-example). With only three denominations a small nested
  search is simpler and more obviously correct than reaching for a
  general knapsack/DP solver.

## Extending it

* **New product** — add a case to the `Sku` enum with its price, then
  `SERVICE` it into stock. Nothing else changes.
* **New coin denomination** — add a case to `Coin`; decide whether it
  participates in `changeDenominations()`.
* **New channel** (HTTP API, event-driven ingestion, a physical
  controller board) — add a new adapter under `Infrastructure/` that
  dispatches the existing `Command`/`Query` objects through the same
  buses. The `Infrastructure/Console` commands are a template for
  this: they contain no business logic, only translating
  input/output.
* **Multiple machines** — already supported; every command/query is
  parameterised by machine id, and both the event store and the read
  model are partitioned by it.
* **New business rule** (e.g. a daily spending cap, loyalty discounts)
  — lives in `VendingMachine::selectProduct()` alongside the existing
  rules, with a new `PurchaseRejectionReason` or event if it needs to
  be observable from the outside.

## Trade-offs / things I'd revisit for a real deployment

* The event bus and projector run synchronously, in-process. That's
  fine for a single machine/process, but a fleet of machines at scale
  would move `event.bus` onto a real transport (e.g. Redis Streams or
  SQS via Messenger) so write-side throughput isn't coupled to
  projection speed.
* The read model does a read-modify-write per event with no locking —
  acceptable here since both the aggregate (single-writer per
  machine thanks to optimistic concurrency) and the projector process
  events in order, but a highly concurrent multi-consumer setup would
  want this to be an atomic Redis operation (Lua script/transaction).
* There is no snapshotting of the event stream. A vending machine's
  history is small enough that replaying it fully on every load is
  irrelevant in practice; a high-traffic aggregate would want periodic
  snapshots.
