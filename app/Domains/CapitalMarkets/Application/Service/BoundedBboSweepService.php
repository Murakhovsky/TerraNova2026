<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use Domains\CapitalMarkets\Application\Contract\MarketSourceRepositoryInterface;
use Domains\CapitalMarkets\Application\Contract\MarketSubscriptionRepositoryInterface;
use Domains\CapitalMarkets\Domain\MarketData\MarketEventType;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceDescriptor;
use Domains\CapitalMarkets\Infrastructure\Persistence\MySql\MysqlBoundedBboSweepGate;
use InvalidArgumentException;
use RuntimeException;

/**
 * Optional bounded REST BBO fallback via canonical ingestion; not a streaming worker.
 * Explicitly rejects partially budgeted source scopes rather than silently truncating.
 */
final readonly class BoundedBboSweepService
{
    public function __construct(
        private MarketSourceRepositoryInterface $sources,
        private MarketSubscriptionRepositoryInterface $subscriptions,
        private MarketSourcePollingService $polling,
        private MysqlBoundedBboSweepGate $gate,
    ) {}

    /** @return array<string,mixed> */
    public function run(string $org,int $maxSources=3,int $maxTargets=10,int $cooldownSeconds=900): array
    {
        if ($org==='' || strlen($org)>190 || $maxSources<1 || $maxSources>3
            || $maxTargets<1 || $maxTargets>10 || $cooldownSeconds<300 || $cooldownSeconds>86400) {
            throw new InvalidArgumentException('Invalid bounded BBO sweep scope.');
        }
        $supported=[
            'binance.spot.rest'=>true,'bybit.spot.v5'=>true,'kraken.spot.rest'=>true,
        ];
        $active=array_values(array_filter($this->sources->list($org,true),
            static fn(MarketSourceDescriptor $source):bool=>isset($supported[$source->adapterType])));
        if (count($active)>$maxSources) {
            throw new RuntimeException('BBO active sources exceed configured sweep admission cap.');
        }
        $rows=[];
        foreach ($active as $source) {
            $subscriptions=$this->subscriptions->forSource($org,$source->id,true);
            if ($subscriptions===[]) {
                $rows[]=['source'=>$source->id->value(),'state'=>'NO_ACTIVE_BBO'];
                continue;
            }
            $targets=[];
            foreach ($subscriptions as $item) {
                if ($item->dataType!==MarketEventType::Bbo) {
                    $rows[]=['source'=>$source->id->value(),'state'=>'BLOCKED_MIXED_SUBSCRIPTIONS'];
                    continue 2;
                }
                $targets[$item->instrumentId->value()]=true;
            }
            $targetCount=count($targets);
            $budget=$source->rateLimitPolicy;
            $windows=max(1,(int)ceil($budget->windowSeconds/$cooldownSeconds));
            // Triple-charge each target: conservative budget for retries/extra requests.
            if ($targetCount>$maxTargets || $targetCount*3*$windows>$budget->requestBudget) {
                $rows[]=['source'=>$source->id->value(),'state'=>'BLOCKED_BUDGET',
                    'active_targets'=>$targetCount,'request_budget'=>$budget->requestBudget];
                continue;
            }
            $outcome=$this->gate->run(
                $org,$source->id->value(),$cooldownSeconds,
                fn():string=>$this->polling->poll($org,$source->id,$targetCount)->status,
            );
            $rows[]=['source'=>$source->id->value(),'active_targets'=>$targetCount]+$outcome;
        }
        return ['mode'=>'BOUNDED_PUBLIC_REST_BBO','streaming_confirmed'=>false,
            'source_count'=>count($active),'rows'=>$rows];
    }
}
