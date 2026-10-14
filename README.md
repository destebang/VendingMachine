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
  (and its payload) and the rejections that record nothing.
* **Integration** (`tests/Integration`) — the MongoDB repository, the Redis
  read-model repository and the
  provision/service/insert-coin/select-item/return-coin use cases against real
  instances.
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
│   │                            # HTTP front controller, infra factories.
│   │                            # No business logic lives here.
│   └── Bus/                     # MessengerCommandBus / MessengerQueryBus adapters
├── Shared/                     # Cross-aggregate, framework-free building blocks
│   ├── Domain/Event/           # DomainEvent + DomainEventBus contracts
│   └── Application/Bus/        # CommandHandler/QueryHandler markers + CommandBus/QueryBus ports
└── VendingMachine/
    ├── Domain/                 # Pure business logic. No framework, no I/O.
    │   ├── Model/               # Money, Denomination, Product, ChangeDue,
    │   │                        # VendedItem, VendingMachineId, VendingMachine
    │   ├── Event/               # Recorded domain events (base + 6 concrete)
    │   ├── Repository/          # VendingMachineRepository (port)
    │   └── Exception/
    ├── Application/            # Use cases: orchestrates the domain,
    │   │                        # talks only to ports.
    │   ├── HealthCheck/         # Stack smoke test (command + handler + result)
    │   ├── ProvisionVendingMachine/  # Create a machine, seed the projection
    │   ├── ServiceVendingMachine/    # Service action: replace stock + cash reserve
    │   ├── InsertCoin/               # Customer inserts a coin; balance grows
    │   ├── SelectItem/               # Customer selects a product; vend + change
    │   ├── ReturnCoin/               # Customer takes back the coins inserted
    │   ├── GetVendingMachineState/   # Read-side query over the Redis projection
    │   └── ReadModel/           # Read-model port + MachineStateView
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

Two Symfony Messenger buses (`config/services.yaml`) carry the two sides, each
with its own synchronous, in-memory handlers locator. `command.bus` handles the
write use cases — `ProvisionVendingMachine`, `ServiceVendingMachine`,
`InsertCoin`, `SelectItem`, `ReturnCoin` and the `HealthCheck` smoke test —
while `query.bus` handles the read-side `GetVendingMachineState` query.

Handlers self-register: a handler class marked `#[CommandHandler]` is
autoconfigured onto `command.bus` and one marked `#[QueryHandler]` onto
`query.bus` by `App\Kernel\ContainerFactory`, so adding a use case is adding the
handler class — no hand-written message→handler map. Symfony's own
`MessengerPass` builds each bus and its handlers locator from those tags, and a
compiler pass refuses to compile when a command or query ends up with zero
handlers or more than one.

The driving adapters depend on framework-free ports, not on Messenger: the
read side (`GetVendingMachineController`, `GetVendingMachineCommand`) asks
through `QueryBus` (`ask(object $query): mixed`) and the write side (every
`*Controller` and `*Command`) handles through `CommandBus`
(`handle(object $command): mixed`). Only the `App\Kernel\Bus\Messenger*Bus`
adapters know the framework; the command adapter also unwraps Messenger's
`HandlerFailedException`, so the adapters catch domain exceptions directly.

### Domain events (write-side foundation)

Every mutation on the `VendingMachine` aggregate records a domain event: a
readonly, timestamp-free fact carrying the aggregate identity and the full
resulting state, plus the action's own data (the coin inserted, the item
vended, the funds returned). Rejections that throw before mutating record
nothing, and an exact-change rejection records the refund before it throws.

The aggregate does not publish events yet — it releases what it has recorded
through `releaseEvents()`. The events live in `App\VendingMachine\Domain\Event\`
and reuse the cross-aggregate, framework-free `App\Shared\Domain\Event\`
contracts (`DomainEvent`, `DomainEventBus`), so a later slice can publish them
and another aggregate can record its own against the same interface.

### MongoDB (write model) / Redis (read model)

`VendingMachineDocument` (Doctrine ODM) is intentionally a separate class
from the `VendingMachine` domain aggregate; `MongoVendingMachineRepository`
is the only place that translates between them, and carries a `version` field
for optimistic concurrency. The Redis read model
(`RedisVendingMachineStateReadModelRepository`) stores one JSON document per
machine and is reachable only through the `VendingMachineStateReadModelRepository`
port. The write commands project a `MachineStateView` into it (provisioning
seeds it, servicing replaces it, returning resets the balance), and
`GetVendingMachineState` serves reads straight from it without touching the
write side. `SelectItem` refreshes it for every outcome, because a rejected
selection can still change the machine — an exact-change refund resets the
balance.

## Extending it

* **New use case** — add a command/handler pair under `Application/`, mark the
  handler `#[CommandHandler]` (or `#[QueryHandler]`) so it self-registers, and
  add a driving adapter (an HTTP route + controller, and/or a console command)
  that dispatches it through the bus.
* **New product, coin, or business rule** — products stay generic
  (`Product`/`Denomination` value objects); a machine is stocked by declaring
  concrete rows, never by hardcoding a catalogue. New rules belong on the
  `VendingMachine` aggregate and are rejected with a domain exception.
* **New channel** — add a new adapter under `Infrastructure/` that handles
  existing commands through the `CommandBus` port (or asks queries through the
  `QueryBus` port). The HTTP controller and console command are the template: no
  business logic, only translating input/output.

## Trade-offs / things I'd revisit next

* The DI container is rebuilt (and recompiled) on every request/command
  invocation rather than cached to disk — acceptable at this scale, but a
  production deployment would dump the compiled container once and reuse it.
* `$_ENV`-based configuration in the infrastructure factories is simple but
  bypasses Symfony's `%env()%` parameter resolution; fine for three
  variables, worth revisiting if configuration grows.
* The read-model port still speaks a raw state array; `MachineStateView` is the
  typed projection on top of it. Folding the DTO into the port signature is a
  natural next step once a second projection shape exists.
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
* A rejected selection is persisted and projected like a successful one, so the
  projection always reflects the balance after the attempt. That costs a
  no-op write on `InsufficientFunds` and `OutOfStock`, where the machine's state
  does not actually change.
* Two Mago rules are tuned in `mago.toml`: `too-many-methods` is raised and
  skips tests (test classes grow one method per case), and
  `inline-variable-return` is off because its fixer strips the `@var` that
  narrows a `mixed` value decoded from JSON. Both are configuration, not
  baselines — there are no baseline files.
