# 0001. Publish integration events via an embedded outbox and a polling relay

Status: accepted

## Context

Until now the vending machine was a closed context: it recorded domain
events and published them synchronously, in-process, to project the Redis
read model. Other contexts (billing, sales, analytics) need to know that an
item was vended — over the network, asynchronously, and without seeing our
domain model.

Two kinds of events are now in play, and they have opposite constraints:

- **Domain events** are internal facts. They carry rich domain objects (the
  full resulting state), change freely with the model, and their consumers
  (the read-model projector) want to run *in the same transaction of thought*
  as the command: synchronously, so reads are consistent the moment a command
  returns.
- **Integration events** are the context's *published language*. They are
  separate classes with small primitive payloads, versioned
  (`vending_machine.item_vended.v1`), and consumed by processes we do
  not control — so they must be durable, ordered per aggregate, and stable
  under change. A Mago guard rule keeps them free of `App\VendingMachine\Domain`.

The hard requirement: a state change and the message announcing it must not
diverge. Publishing to the broker inside the command handler would lose
messages when the process dies between commit and publish; publishing from
the synchronous domain bus would leak broker failures into customer-facing
commands.

## Decision

**Two buses.** The synchronous domain bus (`domain_event.bus`, in-process
Messenger, no transport) keeps driving the read model. A second, asynchronous
**integration bus** carries integration events over AMQP to the durable topic
exchange `vending_machine.integration`, routing by event type, with persistent
messages and publisher confirms. `MessengerIntegrationEventBus` backs the
framework-free `IntegrationEventBus` port; `JsonEnvelopeSerializer` puts a
stable JSON envelope on the wire (never PHP's `serialize()`); a translator
(`VendingMachineIntegrationEventTranslator`) is the only code allowed to see
both event kinds, and drops every domain event that is not part of the
published language.

**Embedded outbox.** `MongoVendingMachineRepository::save()` appends the
aggregate's released events to the document's own `pendingEvents` array
*before* `flush()`. Because MongoDB guarantees atomicity on a single
document, the state change and its outbox entries commit together — no
two-phase commit, no separate outbox collection, no replica set required.
A multikey index on `pendingEvents.eventId` keeps the "documents with pending
events" lookup cheap and supports removal by event id.

**Polling relay.** A console command (`app:outbox:relay`, running as the
`outbox-relay` compose service) polls every ~500 ms, replays each document's
pending entries in aggregate-version order, rebuilds the domain event
(`fromPayload`), translates it, publishes, waits for the broker's confirm,
and only then removes the entry (`$pull` by `eventId`). We chose polling over
MongoDB change streams deliberately: change streams require a replica set
(we run a standalone node) and add resume-token bookkeeping, while the poll
is twenty lines of obvious code and the index makes it cheap. Delivery is
**at-least-once** — a crash between confirm and removal re-publishes — so the
envelope `id` (the domain event's id) is the deduplication handle consumers
must use.

**Spec-first.** The envelope and the message shape are the public contract
and will be documented with AsyncAPI before more event types follow (see the
linked issues). `correlationId` is, for now, the causing domain event's id.

## Consequences

- A vended item reliably produces exactly one stored outbox entry, atomically
  with the sale; the relay turns it into one confirmed broker message.
- Broker outages never fail a customer command: entries wait and are retried
  on the next poll, in order.
- Consumers must be idempotent (deduplicate on envelope `id`); duplicates are
  possible on crash or on a lost race between a relay pull and a concurrent
  save.
- Polling adds up to ~500 ms of latency and constant cheap queries; both are
  tunable (`--poll-interval-ms`) and replaceable by change streams later
  without touching the contract, if we ever run a replica set.
- The translation layer is the single seam where new event types are
  published: add the `*V1` message, extend the translator's `match`, and
  register the type in the envelope serializer.
