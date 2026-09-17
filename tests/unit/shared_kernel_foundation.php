<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use DateTimeImmutable;
use Kernel\Shared\Application\Pagination;
use Kernel\Shared\Application\Result;
use Kernel\Shared\Domain\AggregateRoot;
use Kernel\Shared\Domain\DateRange;
use Kernel\Shared\Domain\DomainEvent;
use Kernel\Shared\Domain\InvariantViolation;
use Kernel\Shared\Domain\Money;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Shared\Domain\UserId;
use Kernel\Shared\Domain\Uuid;
use Kernel\Shared\Domain\ValueObject;
use Kernel\Shared\Time\Clock;

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final readonly class SampleValue extends ValueObject
{
    public function __construct(public string $value) {}
}

final readonly class SampleEvent implements DomainEvent
{
    public function __construct(private string $id, private DateTimeImmutable $at) {}
    public function eventId(): string { return $this->id; }
    public function eventName(): string { return 'sample.created'; }
    public function occurredAt(): DateTimeImmutable { return $this->at; }
}

final class SampleAggregate extends AggregateRoot
{
    public function __construct(private string $aggregateId) {}
    public function id(): string { return $this->aggregateId; }
    public function emit(DomainEvent $event): void { $this->recordDomainEvent($event); }
}

final readonly class FixedClock implements Clock
{
    public function __construct(private DateTimeImmutable $time) {}
    public function now(): DateTimeImmutable { return $this->time; }
}

$organization = OrganizationId::fromString('default');
expect((string) $organization === 'default', 'OrganizationId should preserve current tenant identifiers.');
expect($organization->equals(OrganizationId::fromString('default')), 'Identifiers with same type/value should be equal.');
expect(!$organization->equals(UserId::fromString('default')), 'Different identifier types must not be equal.');

$uuid = Uuid::v4();
expect(Uuid::fromString((string) $uuid)->equals($uuid), 'Generated UUID must round-trip.');

expect((new SampleValue('x'))->equals(new SampleValue('x')), 'ValueObject equality must use type and state.');
expect(!(new SampleValue('x'))->equals(new SampleValue('y')), 'ValueObject equality must detect different state.');

$event = new SampleEvent((string) Uuid::v4(), new DateTimeImmutable('2026-09-17T12:00:00+00:00'));
$aggregate = new SampleAggregate('aggregate-1');
$aggregate->emit($event);
expect($aggregate->hasRecordedDomainEvents(), 'Aggregate should record domain events.');
expect($aggregate->releaseDomainEvents() === [$event], 'Aggregate should release recorded events in order.');
expect(!$aggregate->hasRecordedDomainEvents(), 'Releasing events should clear the buffer.');

$usd = new Money(1250, 'usd');
expect($usd->currency() === 'USD', 'Money should normalize currency.');
expect($usd->add(new Money(250, 'USD'))->minorUnits() === 1500, 'Money addition should use minor units.');
try {
    $usd->add(new Money(1, 'EUR'));
    throw new RuntimeException('Cross-currency addition must fail.');
} catch (InvariantViolation) {
}

$range = new DateRange(
    new DateTimeImmutable('2026-09-01T00:00:00+00:00'),
    new DateTimeImmutable('2026-09-30T23:59:59+00:00'),
);
expect($range->contains(new DateTimeImmutable('2026-09-17T12:00:00+00:00')), 'DateRange should include moments inside the range.');

$pagination = new Pagination(3, 25);
expect($pagination->offset() === 50 && $pagination->limit() === 25, 'Pagination should calculate offset/limit.');

$success = Result::success(['ok' => true]);
expect($success->isSuccess() && $success->value()['ok'] === true, 'Successful Result should expose its value.');
$failure = Result::failure('failed');
expect($failure->isFailure() && $failure->error() === 'failed', 'Failed Result should expose its error.');

$fixed = new DateTimeImmutable('2026-09-17T12:00:00+00:00');
expect((new FixedClock($fixed))->now() === $fixed, 'Clock contract should be framework-independent.');

echo "Shared Kernel foundation contract passed without Symfony kernel.\n";
