# COS Application Layer and CQRS standard

This document defines the canonical application-message pattern for COS.

## Boundaries

- Domain code owns business rules and domain models.
- `Kernel/Application` owns framework-independent CQRS contracts.
- Symfony Messenger is an Infrastructure/composition adapter and must not leak into `Kernel/Application` or Domain code.
- `Kernel/Event` remains the durable domain-event/store/outbox capability. It is not replaced by the application `event.bus`.

## Canonical message types

### Command

A command requests a state change.

```php
final readonly class CreateLeadCommand implements CommandInterface
{
    public function __construct(public string $email) {}
}

final class CreateLeadHandler implements CommandHandlerInterface
{
    public function __invoke(CreateLeadCommand $command): void
    {
        // application orchestration
    }
}
```

Rules:

- Commands use `command.bus`.
- A command has exactly one handler.
- A command must not be used as a read model query.

### Query

A query requests data without intentionally changing business state.

```php
final readonly class GetLeadQuery implements QueryInterface
{
    public function __construct(public string $leadId) {}
}

final class GetLeadHandler implements QueryHandlerInterface
{
    public function __invoke(GetLeadQuery $query): array
    {
        return [];
    }
}
```

Rules:

- Queries use `query.bus`.
- A query has exactly one handler.
- Queries return their result through the query bus.

### Event

An application event announces that something meaningful already happened.

```php
final readonly class LeadCreated implements EventInterface
{
    public function __construct(public string $leadId) {}
}
```

Rules:

- Events use `event.bus`.
- An event may have zero, one, or many handlers.
- Events are reactions, not requests for a specific receiver to perform work.
- Durable persistence, outbox delivery, replay, and event-store concerns remain under `Kernel/Event` and may be connected to `event.bus` by adapters later.

## Canonical buses

COS exposes three application buses:

```text
command.bus
query.bus
event.bus
```

Application code depends only on:

```text
CommandBusInterface
QueryBusInterface
EventBusInterface
```

Symfony-specific implementations live under:

```text
symfony/src/Infrastructure/Messenger/
```

## Handler registration

Handlers are invokable services and implement one marker interface:

```text
CommandHandlerInterface
QueryHandlerInterface
EventHandlerInterface
```

Symfony autoconfiguration maps these marker interfaces to the corresponding Messenger bus.

## Dependency rule

```text
Domain
  ↑
Application / Kernel Application contracts
  ↑
Infrastructure / Symfony Messenger
```

`Kernel/Application` must stay free of Symfony, Phalcon, PDO, Infrastructure, controller, and transport dependencies.

## Naming

Use explicit intent names:

```text
CreateLeadCommand
CreateLeadHandler
GetLeadQuery
GetLeadHandler
LeadCreated
```

Do not create generic names such as `LeadCommand`, `LeadQuery`, or `LeadEvent` when the business intent can be named precisely.
