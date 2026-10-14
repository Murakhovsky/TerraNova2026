<?php
declare(strict_types=1);

use Domains\CapitalMarkets\Application\Service\BoundedBboSweepPolicy;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentId;
use Domains\CapitalMarkets\Domain\MarketData\MarketEventType;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceId;
use Domains\CapitalMarkets\Domain\MarketData\MarketSubscription;
use Domains\CapitalMarkets\Domain\MarketData\MarketSubscriptionId;
use Domains\CapitalMarkets\Domain\MarketData\MarketSubscriptionStatus;
use Domains\CapitalMarkets\Domain\MarketData\RateLimitPolicy;

require dirname(__DIR__,2).'/vendor/autoload.php';
$assert=static function(bool $good,string $message):void {
    if (!$good) throw new RuntimeException($message);
};
$policy=new BoundedBboSweepPolicy();
$make=static function(int $n,MarketEventType $type=MarketEventType::Bbo):MarketSubscription {
    return new MarketSubscription(MarketSubscriptionId::fromString('s'.$n.'-'.$type->value),
        MarketSourceId::fromString('source'),null,InstrumentId::fromString('instrument-'.$n),
        $type,MarketSubscriptionStatus::Active,new DateTimeImmutable('2026-10-14T00:00:00Z'));
};
$budget=new RateLimitPolicy(60,60);
$assert($policy->evaluate([],$budget,10,900)['status']==='NO_ACTIVE_BBO',
    'Empty BBO sweep must remain idle.');
$ten=array_map($make,range(1,10));
$pass=$policy->evaluate($ten,$budget,10,900);
$assert($pass['status']==='READY' && $pass['targets']===10,
    'Ten public BBO targets should fit the reserved 60 request budget.');
$over=$policy->evaluate(array_map($make,range(1,11)),$budget,10,900);
$assert($over['status']==='BLOCKED_BUDGET','Source target cap cannot be bypassed.');
$small=$policy->evaluate($ten,new RateLimitPolicy(20,60),10,900);
$assert($small['status']==='BLOCKED_BUDGET','Source provider rate budget ignored.');
$longWindow=$policy->evaluate($ten,new RateLimitPolicy(40,3600),10,300);
$assert($longWindow['status']==='BLOCKED_BUDGET','Quota must account for provider window crossing multiple polls.');
$mixed=$policy->evaluate([$make(1),$make(2,MarketEventType::Volume)],$budget,10,900);
$assert($mixed['status']==='BLOCKED_MIXED_SUBSCRIPTIONS','BBO-only scheduler must reject other data types.');
$dedup=$policy->evaluate([$make(1),$make(1)],$budget,10,900);
$assert($dedup['status']==='READY' && $dedup['targets']===1,
    'Duplicate instrument subscriptions must not inflate or truncate target count.');
echo "Bounded BBO admission policy passed.\n";
