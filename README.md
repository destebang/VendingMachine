# Vending Machine

A vending machine, built on standalone Symfony components (no
`framework-bundle`), with a MongoDB-backed write model (Doctrine ODM) and a
Redis-backed read model.

**Current state: first customer-facing slice.** The whole stack boots
end-to-end — HTTP, CLI, MongoDB, Redis, dependency injection, a Messenger
command bus, and a three-layer test suite — and carries its first real use
cases: provision a vending machine, service it (declare its true item stock and
coin reserve), read its current state back, and insert coins toward the price of
an item. The write side lives in MongoDB (Doctrine ODM), the read side in Redis,
and the two are kept in step by the command handlers. A health check still
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

The same use cases are available through the CLI, dispatching the exact same
commands through the same bus:

```bash
docker compose exec app php bin/console app:vending-machine:provision
docker compose exec app php bin/console app:vending-machine:service $ID \
  --stock='[{"selectorCode":"WATER","priceInCents":65,"stock":10}]' \
  --cash-reserve='[{"denominationInCents":100,"quantity":5}]'
docker compose exec app php bin/console app:vending-machine:insert-coin $ID --denomination-in-cents=25
docker compose exec app php bin/console app:vending-machine:show $ID
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
  (`Money`, `Denomination`, `Product`, `VendingMachine`), exercised in
  isolation with no I/O, including the service replace semantics, the
  mid-transaction rejection, and coin insertion (accepted denominations
  accumulating, rejected ones leaving the balance untouched).
* **Integration** (`tests/Integration`) — the MongoDB repository, the Redis
  read-model repository and the provision/service/insert-coin use cases against
  real instances.
* **Functional** (`tests/Functional`) — boots the real HTTP application and the
  real CLI commands and exercises provisioning, servicing, inserting coins and
  reading state (plus the mid-transaction and rejected-denomination cases)
  through the full request/response (and input/output) cycle, Messenger bus
  included.

CI runs all three suites automatically on every push and pull request (see
`.github/workflows/tests.yml`).

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
└── VendingMachine/
    ├── Domain/                 # Pure business logic. No framework, no I/O.
    │   ├── Model/               # Money, Denomination, Product,
    │   │                        # VendingMachineId, VendingMachine
    │   ├── Repository/          # VendingMachineRepository (port)
    │   └── Exception/
    ├── Application/            # Use cases: orchestrates the domain,
    │   │                        # talks only to ports.
    │   ├── HealthCheck/         # Stack smoke test (command + handler + result)
    │   ├── ProvisionVendingMachine/  # Create a machine, seed the projection
    │   ├── ServiceVendingMachine/    # Service action: replace stock + cash reserve
    │   ├── InsertCoin/               # Customer inserts a coin; balance grows
    │   ├── GetVendingMachineState/   # Read-side query over the Redis projection
    │   └── ReadModel/           # Read-model port + MachineStateView
    └── Infrastructure/         # Adapters: the only place that knows about
        │                        # Mongo/Redis/HTTP/console.
        ├── Persistence/Mongo/   # Doctrine ODM document + repository
        ├── ReadModel/Redis/     # Redis-backed read model repository
        ├── Http/                # HealthCheck + provision/service/insert-coin/get controllers
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
`ServiceVendingMachine`, `InsertCoin`, the `GetVendingMachineState` query and
the `HealthCheck` smoke test all go through it. The remaining vending-machine
commands (select product, return coin) will follow the same shape.

### MongoDB (write model) / Redis (read model)

`VendingMachineDocument` (Doctrine ODM) is intentionally a separate class
from the `VendingMachine` domain aggregate; `MongoVendingMachineRepository`
is the only place that translates between them, and carries a `version` field
for optimistic concurrency. The Redis read model
(`RedisVendingMachineStateReadModelRepository`) stores one JSON document per
machine and is reachable only through the `VendingMachineStateReadModelRepository`
port. Both write commands project a `MachineStateView` into it (provisioning
seeds it, servicing replaces it), and `GetVendingMachineState` serves reads
straight from it without touching the write side.

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
  bypasses Symfony's `%env()%` parameter resolution; fine for three
  variables, worth revisiting if configuration grows.
* The read-model port still speaks a raw state array; `MachineStateView` is the
  typed projection on top of it. Folding the DTO into the port signature is a
  natural next step once a second projection shape exists.
* The accepted-denomination allow-list lives on the `VendingMachine`
  aggregate (`ACCEPTED_DENOMINATIONS_IN_CENTS`) rather than being configurable
  per machine. Fine while every machine takes the same coins; a per-machine set
  would move onto the aggregate and into the Mongo document.
* There is still no use case for selecting a product or returning change, so
  inserted funds can only grow — nothing spends or refunds them yet. The
  aggregate already guards servicing mid-transaction, and `InsertCoin` is the
  first driving adapter that grows the balance.
