# Vending Machine

A vending machine, built on standalone Symfony components (no
`framework-bundle`), with a MongoDB-backed write model (Doctrine ODM) and a
Redis-backed read model.

**Current state: bootstrap skeleton.** This proves the whole stack boots
end-to-end — HTTP, CLI, MongoDB, Redis, dependency injection, a Messenger
command bus, and a three-layer test suite — before any vending machine
business logic is built on top of it. There are no use cases yet beyond a
health check that round-trips data through both stores.

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
  isolation with no I/O.
* **Integration** (`tests/Integration`) — the MongoDB repository and the
  Redis read-model repository against real instances.
* **Functional** (`tests/Functional`) — boots the real HTTP application and
  the real CLI command and exercises the health check through the full
  request/response (and input/output) cycle, Messenger bus included.

CI runs all three suites automatically on every push and pull request (see
`.github/workflows/tests.yml`).

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
    │   ├── HealthCheck/         # Command + handler + result (today's only
    │   │                        # use case: a stack smoke test)
    │   └── ReadModel/           # Read-model port
    └── Infrastructure/         # Adapters: the only place that knows about
        │                        # Mongo/Redis/HTTP/console.
        ├── Persistence/Mongo/   # Doctrine ODM document + repository
        ├── ReadModel/Redis/     # Redis-backed read model repository
        ├── Http/                # HealthCheckController
        └── Console/             # HealthCheckCommand
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
`HandlersLocator`. Every use case is a command dispatched through it and
handled by exactly one handler, which is the shape the real vending-machine
commands (insert coin, select product, return coin, service) will follow —
today there is only `HealthCheck` to exercise the wiring.

### MongoDB (write model) / Redis (read model)

`VendingMachineDocument` (Doctrine ODM) is intentionally a separate class
from the `VendingMachine` domain aggregate; `MongoVendingMachineRepository`
is the only place that translates between them, and already carries a
`version` field for optimistic concurrency. The Redis read model
(`RedisVendingMachineStateReadModelRepository`) stores one JSON document per
machine and is reachable only through the `VendingMachineStateReadModelRepository`
port — no mapping logic yet, since there is no state worth projecting until
the first real use case lands.

## Extending it

* **New use case** — add a command/handler pair under `Application/`,
  register it in `command_bus.handlers_locator` (services.yaml), and add a
  driving adapter (an HTTP route + controller, and/or a console command)
  that dispatches it through the bus.
* **New product, coin, or business rule** — none of these exist yet beyond
  the generic `Product`/`Denomination` value objects; they'll be introduced
  alongside the first real use case rather than hardcoded in advance.
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
* No snapshotting, projections, or business rules exist yet — intentionally
  out of scope for this bootstrap step.
