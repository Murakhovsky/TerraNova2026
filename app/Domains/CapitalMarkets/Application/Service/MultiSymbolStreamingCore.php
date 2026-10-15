<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use DomainException;
use Domains\CapitalMarkets\Application\Contract\MarketSourceRepositoryInterface;
use Domains\CapitalMarkets\Application\Contract\MarketInstrumentResolverInterface;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceId;
use Domains\CapitalMarkets\Domain\MarketData\MarketEventType;

/**
 * Shared admission & deterministic topology for all source-fenced BBO workers.
 *
 * A source is one lease and one native WS connection. This MUST NOT invent
 * extra shards within a source: MysqlStreamingWorkerLease forbids parallel
 * sibling shards until an explicitly disjoint, fenced partition contract exists.
 *
 * Admission is a read-only preflight, NOT a transport ACK / deployment action.
 */
final readonly class MultiSymbolStreamingCore
{
    private const PROVIDERS=[
        'binance.spot.rest'=>['provider'=>'BINANCE_SPOT','limit'=>100,'minimum'=>2,
            'command'=>'cos:capital-markets:market-data:stream:binance-spot-batch'],
        'massive.stocks.v2'=>['provider'=>'MASSIVE_STOCKS','limit'=>25,'minimum'=>2,
            'command'=>'cos:capital-markets:market-data:stream:massive-stocks-batch'],
    ];
    public function __construct(
        private MarketSourceRepositoryInterface $sources,
        private StreamingSubscriptionPlanner $subscriptions,
        private MarketInstrumentResolverInterface $resolver,
    ){}

    /**
     * @param list<string> $sourceIds
     * @return array<string,mixed>
     */
    public function plan(string $org,array $sourceIds):array
    {
        if($org===''||strlen($org)>190||count($sourceIds)<1||count($sourceIds)>64
            ||count(array_unique($sourceIds))!==count($sourceIds)){
            throw new DomainException('One to 64 distinct source IDs and one tenant required.');
        }
        $result=[];$symbols=[];$totals=['BBO'=>0,'SOURCES'=>0];
        foreach($sourceIds as $sourceId){
            if(!is_string($sourceId)||$sourceId===''||strlen($sourceId)>190
                ||trim($sourceId)!==$sourceId){
                throw new DomainException('Invalid source identity.');
            }
            $id=MarketSourceId::fromString($sourceId);
            $source=$this->sources->get($org,$id);
            if($source===null||!$source->enabled){
                throw new DomainException('Missing or disabled streaming source '.$sourceId);
            }
            $kind=self::provider($source->adapterType);
            $desired=$this->subscriptions->desired($org,$id);
            $n=count($desired);
            if($n<$kind['minimum']||$n>$kind['limit']){
                throw new DomainException(sprintf(
                    '%s source %s has %d subscriptions, permitted %d..%d. Split by separately registered, fenced sources.',
                    $kind['provider'],$sourceId,$n,$kind['minimum'],$kind['limit']
                ));
            }
            $rows=[];$seen=[];
            foreach($desired as $key=>$subscription){
                if($subscription->dataType!==MarketEventType::Bbo){
                    throw new DomainException('BBO multiplexing does not silently accept L2/delta subscriptions.');
                }
                if($source->venueId===null && $subscription->venueId!==null
                    ||$source->venueId!==null && (
                        $subscription->venueId===null
                        ||!$source->venueId->equals($subscription->venueId)
                    )){
                    throw new DomainException('BBO subscription crossed source venue.');
                }
                $target=$this->resolver->target($org,$source,$subscription->instrumentId);
                if($target===null||($source->venueId===null)!==($target->venueInstrument===null)){
                    throw new DomainException('BBO subscription lacks exact canonical source mapping.');
                }
                $symbol=$target->externalSymbol;
                $normalized=strtoupper($symbol);
                if(isset($seen[$normalized])){
                    throw new DomainException('Duplicate provider symbol in source batch.');
                }
                $seen[$normalized]=true;
                $rows[]=['key'=>$key,'instrument_id'=>$subscription->instrumentId->value(),
                    'symbol'=>$symbol];
                // The same canonical instrument may be subscribed on different
                // providers; it is NOT a duplicate financial event.
                $symbols[$sourceId.'|'.$normalized]=true;
            }
            usort($rows,static fn(array $a,array $b):int=>strcmp($a['key'],$b['key']));
            $result[]=[
                'source_id'=>$sourceId,
                'adapter_type'=>$source->adapterType,
                'provider'=>$kind['provider'],
                'capacity'=>$kind['limit'],
                'subscriptions'=>$n,
                'headroom'=>$kind['limit']-$n,
                'shard'=>'ONE_FENCED_SOURCE_LEASE',
                'worker_command'=>$kind['command'],
                'reconnect'=>[
                    'initial_delay_ms'=>$source->reconnectPolicy->initialDelayMilliseconds,
                    'max_delay_ms'=>$source->reconnectPolicy->maximumDelayMilliseconds,
                    'max_attempts'=>$source->reconnectPolicy->maximumAttempts,
                ],
                'keys'=>array_column($rows,'key'),
                'instruments'=>array_column($rows,'instrument_id'),
                'symbols'=>array_column($rows,'symbol'),
            ];
            $totals['BBO']+=$n;$totals['SOURCES']++;
        }
        if($totals['BBO']>6400)throw new DomainException('Global BBO plan over capacity.');
        usort($result,static fn(array $a,array $b):int=>strcmp($a['source_id'],$b['source_id']));
        return [
            'status'=>'PLANNED_NOT_RUNNING',
            'tenant'=>$org,'totals'=>$totals,
            'unique_source_symbols'=>count($symbols),
            'source_scoped_leases'=>true,
            'no_parallel_same_source_shards'=>true,
            'network_sessions_opened'=>0,
            'sources'=>$result,
        ];
    }

    /** @return array{provider:string,limit:int,minimum:int,command:string} */
    public static function provider(string $adapterType):array
    {
        if(!isset(self::PROVIDERS[$adapterType])){
            throw new DomainException('No approved multi-symbol BBO implementation for adapter '.$adapterType);
        }
        return self::PROVIDERS[$adapterType];
    }

    public static function assertBatch(string $adapterType,int $count):void
    {
        $profile=self::provider($adapterType);
        if($count<$profile['minimum']||$count>$profile['limit']){
            throw new DomainException('Streaming batch size exceeds vetted provider capacity.');
        }
    }
}
