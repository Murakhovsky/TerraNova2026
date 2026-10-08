<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Domain\Event;
use DateTimeImmutable;
use InvalidArgumentException;
final readonly class CapitalRiskLifecycleEvent extends AbstractCapitalMarketsEvent
{
 private const TYPES=[
  'capital_markets.portfolio.risk_state_changed.v1',
  'capital_markets.allocation.plan_created.v1',
  'capital_markets.allocation.approved.v1',
  'capital_markets.allocation.rejected.v1',
  'capital_markets.strategy.allocation_changed.v1',
  'capital_markets.capital.reservation_created.v1',
  'capital_markets.capital.released.v1',
  'capital_markets.rebalance.required.v1',
  'capital_markets.rebalance.plan_created.v1',
  'capital_markets.exposure.limit_approaching.v1',
  'capital_markets.exposure.limit_breached.v1',
  'capital_markets.concentration.limit_breached.v1',
  'capital_markets.margin.utilization_high.v1',
 ];
 public function __construct(private string $type,string $eventId,DateTimeImmutable $occurredAt,string $organizationId,string $aggregateId,array $payload=[]){
  if(!in_array($type,self::TYPES,true))throw new InvalidArgumentException('Unsupported Capital Markets capital/risk event type.');
  parent::__construct($eventId,$occurredAt,$organizationId,$aggregateId,$payload,1);
 }
 public function eventName():string{return $this->type;}
}
