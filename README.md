# Vending Machine

A vending machine, built on standalone Symfony components (no
`framework-bundle`), with a MongoDB-backed write model (Doctrine ODM) and a
Redis-backed read model.

**Current state: the full customer journey.** The whole stack boots end-to-end —
HTTP, CLI, MongoDB, Redis, dependency injection, a Messenger command bus, and a
three-layer test suite — and carries the customer-facing use cases: provision a
vending machine, service it (declare its true item stock and coin reserve), read
its current state back, insert coins toward the price of an item, select a
product to vend it and hand back any change due, and return every inserted coin
before buying. The write side lives in MongoDB (Doctrine ODM), the read side in
Redis, and the two are kept in step by the command handlers. A health check still
round-trips data through both stores.

## Requirements

* Docker & Docker Compose (everything runs in containers — no local
  PHP/MongoDB/Redis needed)
* To run things outside Docker instead: PHP ≥ 8.4 with the `mongodb`
  extension, Composer, a reachable MongoDB 7 and Redis 7

## Running it

```bash
docker compose up -d --build
```

This starts three services: `app` (PHP, serving HTTP on `:8000`),
`mongodb`, and `redis`.

Check that everything is wired up correctly, via HTTP:

```bash
curl http://localhost:8000/health
# -> {"mongo":true,"redis":true,"healthy":true}
```

...or via the CLI, which dispatches the exact same command through the same
bus:

```bash
docker compose exec app php bin/console app:health-check
# -> {"mongo":true,"redis":true,"healthy":true}
```

## Using it

Provision a machine (empty stock, empty cash reserve, no inserted funds) and
note its id:

```bash
curl -s -X POST http://localhost:8000/vending-machines
# -> {"id":"01a12f94-62ac-771b-a72a-f8a35ea379bb"}
```

Service it: declare its **true** item stock and coin reserve. These replace the
previous state, they are not added to it — and the action is rejected with a
`PendingTransactionInProgress` (HTTP 409) while a customer transaction is in
flight.

```bash
curl -s -X POST http://localhost:8000/vending-machines/$ID/service \
  -H 'Content-Type: application/json' \
  -d '{"stock":[{"selectorCode":"WATER","priceInCents":65,"stock":10}],
       "cashReserve":[{"denominationInCents":100,"quantity":5}]}'
```

Read the current state back from the Redis read model:

```bash
curl -s http://localhost:8000/vending-machines/$ID
# -> {"id":"...","stock":[{"selectorCode":"WATER","priceInCents":65,"stock":10}],
#     "cashReserve":[{"denominationInCents":100,"quantity":5}],"insertedFundsInCents":0}
```

Insert a coin: the machine accepts 5, 10, 25 and 100 cent coins, rejects
anything else, and the response carries the updated balance
(`insertedFundsInCents`):

```bash
curl -s -X POST http://localhost:8000/vending-machines/$ID/coins \
  -H 'Content-Type: application/json' \
  -d '{"denominationInCents":25}'
# -> {"id":"...","stock":[...],"cashReserve":[...],"insertedFundsInCents":25}
```

Select a product: when the inserted funds cover its price, the machine vends it
and returns any change due, made from its cash reserve (never from the coins just
inserted), decrementing stock and reserve and resetting the balance:

```bash
curl -s -X POST http://localhost:8000/vending-machines/$ID/selections \
  -H 'Content-Type: application/json' \
  -d '{"selectorCode":"WATER"}'
# -> {"id":"...","selectorCode":"WATER","priceInCents":65,"change":[25,10],"insertedFundsInCents":0}
```

A selection the machine cannot serve is rejected with a clear reason (HTTP 409):
`InsufficientFunds` (the balance is kept so the customer can top up), `OutOfStock`
(the balance is kept), or `InsufficientChangeAvailable` (the whole balance is
returned).

Changed your mind before selecting? Return every inserted coin: the machine hands
back exactly the coins you put in — never a set drawn from its change reserve —
and resets the balance to zero. Returning with nothing inserted is a no-op:

```bash
curl -s -X POST http://localhost:8000/vending-machines/$ID/return-coin
# -> {"id":"...","returnedCoins":[10,10],"insertedFundsInCents":0}
```

The same use cases are available through the CLI, dispatching the exact same
commands through the same bus:

```bash
docker compose exec app php bin/console app:vending-machine:provision
docker compose exec app php bin/console app:vending-machine:service $ID \
  --stock='[{"selectorCode":"WATER","priceInCents":65,"stock":10}]' \
  --cash-reserve='[{"denominationInCents":100,"quantity":5}]'
docker compose exec app php bin/console app:vending-machine:insert-coin $ID --denomination-in-cents=25
docker compose exec app php bin/console app:vending-machine:select-item $ID WATER
docker compose exec app php bin/console app:vending-machine:return-coin $ID
docker compose exec app php bin/console app:vending-machine:show $ID
```

### Worked examples

A machine serviced with `WATER` (0.65), `JUICE` (1.00) and `SODA` (1.50), and a
change reserve of 40×0.25, 40×0.10 and 40×0.05, replays the specification's
examples:

```
1, 0.25, 0.25, select SODA   -> SODA
1, select WATER              -> WATER, 0.25, 0.10
0.25, select WATER           -> InsufficientFunds (balance kept)
0.10, 0.10, return-coin      -> 0.10, 0.10
```

## Running the tests

```bash
docker compose exec app vendor/bin/phpunit --testsuite=unit          # fast, no infra

docker compose exec -e MONGO_DB=vending_test -e REDIS_URL=redis://redis:6379/1 \
  app vendor/bin/phpunit --testsuite=integration

docker compose exec -e MONGO_DB=vending_test -e REDIS_URL=redis://redis:6379/1 \
  app vendor/bin/phpunit --testsuite=functional

# or simply, for everything:
docker compose exec -e MONGO_DB=vending_test -e REDIS_URL=redis://redis:6379/1 \
  app vendor/bin/phpunit
```

* **Unit** (`tests/Unit`) — domain value objects and entities
  (`Money`, `Denomination`, `Product`, `ChangeDue`, `InsertedFunds`,
  `VendingMachine`), exercised in isolation with no I/O, including the service
  replace semantics, the mid-transaction rejection, coin insertion (accepted
  denominations accumulating, rejected ones leaving the balance untouched),
  returning inserted funds (the exact coins handed back, the no-op case and the
  reserve staying untouched), change-making (largest denominations first,
  backtracking when a greedy choice cannot be completed) and all four selection
  outcomes. It also covers the domain events: the event each mutation records
  (and its payload), the rejections that record nothing, and the projector that
  turns each event into the read-side view.
* **Integration** (`tests/Integration`) — the MongoDB repository, the typed Redis
  read-model repository and the
  provision/service/insert-coin/select-item/return-coin use cases against real
  instances, all driven through the real command bus. Each write command appends
  the expected traceability document(s) to `domain_events` (and the rejections
  that mutate nothing append none), and the event-driven projection keeps the
  Redis read model in step.
* **Functional** (`tests/Functional`) — boots the real HTTP application and the
  real CLI commands and exercises provisioning, servicing, inserting coins,
  reading state, selecting products and returning inserted coins (plus the
  mid-transaction, rejected-denomination and rejected-selection cases) through
  the full request/response (and input/output) cycle, Messenger bus included.
  The four specification examples are replayed end-to-end.

CI runs all three suites automatically on every push and pull request (see
`.github/workflows/tests.yml`).

## Code quality

Formatting, linting, static analysis and architectural guards are handled by
[Mago](https://mago.carthage.software/), configured in [`mago.toml`](./mago.toml).
The guard enforces the hexagonal layering (Domain ← Application ←
Infrastructure), keeps Symfony, Doctrine, MongoDB and Predis out of the inner
layers, and pins naming and modifier conventions on controllers, commands,
repositories, domain models and domain exceptions.

Run the four checks through Composer (or `vendor/bin/mago <tool>` directly):

```bash
composer fmt:check   # mago format --check
composer lint        # mago lint
composer analyse     # mago analyze
composer guard       # mago guard

# or all four at once:
composer quality
```

`composer fmt` rewrites files, and `composer fix` runs the full
guard → analyze → lint → format loop until it converges.

Both workflows run on every push and pull request: `.github/workflows/tests.yml`
runs the three test suites, and `.github/workflows/quality.yml` runs the four
checks above.

### Optional pre-commit hook

A pre-commit hook that runs `mago format --staged`, `mago lint --staged` and
`mago analyze --staged` is committed under [`hooks/`](./hooks). Enable it once
per clone:

```bash
git config core.hooksPath hooks
```

It uses a local `vendor/bin/mago` when available and otherwise runs Mago inside
the app container; when neither is available it skips with a warning, since CI
enforces the checks regardless.

## Domain language

The vocabulary this codebase speaks — stock, cash reserve, inserted funds,
pending transaction, service — is defined in [`CONTEXT.md`](./CONTEXT.md).
Prefer those terms in code, comments and commits.

## Architecture

```
src/
├── Kernel/                     # Hand-assembled bootstrap: DI container,
│                                # HTTP front controller, infra factories.
│                                # No business logic lives here.
├── Shared/                     # Cross-aggregate building blocks
│   ├── Domain/Event/           # DomainEvent + DomainEventBus contracts
│   └── Infrastructure/EventBus/ # Collector, release middleware, Messenger
│                                # bus, MongoDB traceability store
└── VendingMachine/
    ├── Domain/                 # Pure business logic. No framework, no I/O.
    │   ├── Model/               # Money, Denomination, Product, ChangeDue,
    │   │                        # VendedItem, VendingMachineId, VendingMachine
    │   ├── Event/               # Recorded domain events (base + 6 concrete)
    │   ├── Read/                # MachineStateView + read-model port
    │   ├── Repository/          # VendingMachineRepository (port)
    │   └── Exception/
    ├── Application/            # Use cases: orchestrates the domain,
    │   │                        # talks only to ports.
    │   ├── HealthCheck/         # Stack smoke test (command + handler + result)
    │   ├── ProvisionVendingMachine/  # Create a machine
    │   ├── ServiceVendingMachine/    # Service action: replace stock + cash reserve
    │   ├── InsertCoin/               # Customer inserts a coin; balance grows
    │   ├── SelectItem/               # Customer selects a product; vend + change
    │   ├── ReturnCoin/               # Customer takes back the coins inserted
    │   ├── GetVendingMachineState/   # Read-side query over the Redis projection
    │   └── Read/                # VendingMachineProjector (events -> read model)
    └── Infrastructure/         # Adapters: the only place that knows about
        │                        # Mongo/Redis/HTTP/console.
        ├── Persistence/Mongo/   # Doctrine ODM document + repository
        ├── ReadModel/Redis/     # Redis-backed read model repository
        ├── Http/                # HealthCheck + provision/service/insert-coin/select/return/get controllers
        └── Console/             # HealthCheck + vending-machine commands
```

This is a hexagonal/ports-and-adapters layout: `Domain` and `Application`
depend only on **interfaces** (`VendingMachineRepository`,
`VendingMachineStateReadModelRepository`); `Infrastructure` provides the
Mongo/Redis/HTTP/console implementations, wired together in
`config/services.yaml`. Swapping MongoDB or Redis for something else, or
adding a new driving adapter next to HTTP and the console, never touches the
domain.

### No framework bundle

There is no `symfony/framework-bundle` here — only standalone components.
`src/Kernel/ContainerFactory` builds a `Symfony\Component\DependencyInjection\ContainerBuilder`
from `config/services.yaml` and compiles it; `src/Kernel/HttpApplication`
wires `symfony/routing` + `symfony/http-kernel`'s `HttpKernel` directly
around that container; `bin/console` builds a plain `symfony/console`
`Application` from the same container. Both entry points therefore share
identical service wiring.

### CQRS-shaped from day one

A single Symfony Messenger command bus (`config/services.yaml`,
`command.bus`) is configured with a synchronous, in-memory
`HandlersLocator`. Every use case is a command or query dispatched through it
and handled by exactly one handler: `ProvisionVendingMachine`,
`ServiceVendingMachine`, `InsertCoin`, `SelectItem`, `ReturnCoin`, the
`GetVendingMachineState` query and the `HealthCheck` smoke test all go through
it.

### Domain events (recorded, published, persisted)

Every mutation on the `VendingMachine` aggregate records a domain event: a
readonly, timestamp-free fact carrying the aggregate identity and the full
resulting state, plus the action's own data (the coin inserted, the item
vended, the funds returned). Rejections that throw before mutating record
nothing, and an exact-change rejection records the refund before it throws.

The events live in `App\VendingMachine\Domain\Event\` and reuse the
cross-aggregate, framework-free `App\Shared\Domain\Event\` contracts
(`DomainEvent`, `DomainEventBus`), so another aggregate can record its own
against the same interface.

Publication is structural, not per-handler. `MongoVendingMachineRepository::save()`
drains the aggregate's recorded events into a per-request
`DomainEventCollector` right after a successful flush; the
`ReleaseDomainEventsMiddleware`, sitting outside `handle_message_middleware` on
the command bus, drains that collector in a `finally` and publishes everything
on a second Messenger bus (`event.bus`) — for every outcome, including a
rejection that mutated state or a handler that throws after committing. A
failing listener is swallowed and logged, never failing the original command.

A generic `MongoDomainEventStore` listener appends every event to the
`domain_events` collection for traceability, deriving `aggregateType` and
`eventType` from the event's FQCN and stamping `occurredAt` at write time.

### MongoDB (write model) / Redis (read model)

`VendingMachineDocument` (Doctrine ODM) is intentionally a separate class
from the `VendingMachine` domain aggregate; `MongoVendingMachineRepository`
is the only place that translates between them, and carries a `version` field
for optimistic concurrency.

The Redis read model (`RedisVendingMachineStateReadModelRepository`) stores one
JSON document per machine and is reachable only through the typed
`VendingMachineStateReadModelRepository` port (`put(MachineStateView)` /
`get(): ?MachineStateView`), which lives in `Domain/Read/` alongside the
read-side `MachineStateView`; the adapter owns the key and storage format.

Projection is **event-driven**: the write handlers no longer push to the read
model — they are `get → domain call → save`. The `VendingMachineProjector`
reacts to each published domain event and projects the state the event carries
into the read model. A rejected selection that mutated state (the exact-change
refund) still commits, so it still records its event and the projection still
reflects the reset balance. `GetVendingMachineState` serves reads straight from
the projection without touching the write side.

## Extending it

* **New use case** — add a command/handler pair under `Application/`,
  register it in `command_bus.handlers_locator` (services.yaml), and add a
  driving adapter (an HTTP route + controller, and/or a console command)
  that dispatches it through the bus.
* **New product, coin, or business rule** — products stay generic
  (`Product`/`Denomination` value objects); a machine is stocked by declaring
  concrete rows, never by hardcoding a catalogue. New rules belong on the
  `VendingMachine` aggregate and are rejected with a domain exception.
* **New channel** — add a new adapter under `Infrastructure/` that
  dispatches existing commands/queries through the same bus. The HTTP
  controller and console command are the template: no business logic,
  only translating input/output.

## Trade-offs / things I'd revisit next

* The DI container is rebuilt (and recompiled) on every request/command
  invocation rather than cached to disk — acceptable at this scale, but a
  production deployment would dump the compiled container once and reuse it.
* `$_ENV`-based configuration in the infrastructure factories is simple but
  bypasses Symfony's `%env()%` parameter resolution; fine for a few variables,
  worth revisiting if configuration grows.
* The accepted-denomination allow-list lives on the `VendingMachine`
  aggregate (`ACCEPTED_DENOMINATIONS_IN_CENTS`) rather than being configurable
  per machine. Fine while every machine takes the same coins; a per-machine set
  would move onto the aggregate and into the Mongo document.
* Inserted funds are modelled as the collection of the individual coins
  inserted (`InsertedFunds`), so a return can hand back exactly those coins —
  never a set drawn from the reserve. The read model still projects only their
  total (`insertedFundsInCents`); exposing the literal coins on the query side
  is a natural next step if a channel needs it.
* Change is made from the cash reserve with a bounded backtracking search over
  the denominations the machine actually holds. There is no cap on which
  denomination can be given back, so a reserve holding 1-unit coins would return
  them as change; the specification's "1 coins are never recirculated" rule would
  be a small, explicit filter on the search if it were wanted.
* A rejected selection is still committed (`SelectItemHandler` saves in a
  `finally`), so an exact-change refund records its event and the projection
  reflects the reset balance. `InsufficientFunds` and `OutOfStock` commit a
  no-op write and publish nothing, since the machine's state did not change.
* Two Mago rules are tuned in `mago.toml`: `too-many-methods` is raised and
  skips tests (test classes grow one method per case), and
  `inline-variable-return` is off because its fixer strips the `@var` that
  narrows a `mixed` value decoded from JSON. Both are configuration, not
  baselines — there are no baseline files.
