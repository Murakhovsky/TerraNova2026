<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use DomainException;
use Domains\CapitalMarkets\Application\Contract\MarketDataIngestionInterface;
use Domains\CapitalMarkets\Application\Contract\MarketDataProviderAvailabilityInterface;
use Domains\CapitalMarkets\Application\Contract\MarketInstrumentResolverInterface;
use Domains\CapitalMarkets\Application\Contract\MarketSourceRepositoryInterface;
use Domains\CapitalMarkets\Application\Contract\MarketSubscriptionRepositoryInterface;
use Domains\CapitalMarkets\Application\DTO\MarketSourcePollResult;
use Domains\CapitalMarkets\Application\Contract\MarketSourceRestWriterGateInterface;
use Domains\CapitalMarkets\Application\Exception\MarketSourceStreamingActiveException;
use Domains\CapitalMarkets\Domain\Contract\MarketClockInterface;
use Domains\CapitalMarkets\Domain\MarketData\MarketConnectionState;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataCapability;
use Domains\CapitalMarkets\Domain\MarketData\MarketEventType;
use Domains\CapitalMarkets\Domain\MarketData\MarketRateLimitState;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceHealth;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceId;
use Throwable;

final readonly class MarketSourcePollingService
{
    public function __construct(
        private MarketSourceRepositoryInterface $sources,
        private MarketSubscriptionRepositoryInterface $subscriptions,
        private MarketInstrumentResolverInterface $instruments,
        private MarketDataAdapterRegistry $adapters,
        private MarketDataProviderAvailabilityInterface $availability,
        private MarketDataIngestionInterface $ingestion,
        private MarketClockInterface $clock,
        private MarketSourceRestWriterGateInterface $writerGate,
    ){}

    public function poll(string $organizationId,MarketSourceId $sourceId,int $targetLimit=100):MarketSourcePollResult
    {
        $targetLimit=max(1,min(1000,$targetLimit));
        $source=$this->sources->get($organizationId,$sourceId);
        if($source===null)throw new DomainException('Market-data source is not configured.');
        if(!$source->enabled)return new MarketSourcePollResult('SOURCE_DISABLED',0,0,0,0,0);
        if(!$this->availability->enabled($organizationId,$source)){
            return new MarketSourcePollResult('PROVIDER_DISABLED',0,0,0,0,0);
        }

        $adapter=$this->adapters->get($source->adapterType);
        $groups=[];$errors=[];$configurationFailures=0;
        foreach($this->subscriptions->forSource($organizationId,$sourceId,true) as $subscription){
            $key=$subscription->instrumentId->value();
            if(!isset($groups[$key])&&count($groups)>=$targetLimit)continue;
            $target=$groups[$key]['target']??$this->instruments->target($organizationId,$source,$subscription->instrumentId);
            if($target===null){
                $configurationFailures++;
                if(count($errors)<10)$errors[]='Unresolved subscription target: '.$subscription->instrumentId->value();
                continue;
            }
            $capability=$this->capability($subscription->dataType);
            if(!$adapter->supports($capability,$target)){
                $configurationFailures++;
                if(count($errors)<10)$errors[]='Adapter does not support '.$capability->value.' for '.$subscription->instrumentId->value();
                continue;
            }
            $groups[$key]??=['target'=>$target,'capabilities'=>[]];
            $groups[$key]['capabilities'][$capability->value]=$capability;
        }

        $rawEvents=0;$accepted=0;$duplicates=0;$failed=$configurationFailures;$polled=0;
        foreach($groups as $group){
            try{
                $batch=$adapter->getSnapshot(
                    $organizationId,
                    $source,
                    $group['target'],
                    array_values($group['capabilities']),
                );
                $polled++;
                // Do NOT hold MySQL source lock during outbound provider I/O.
                // Once the batch arrives, publication and health updates are
                // admitted atomically against any competing WS lease acquire.
                $outcome=$this->writerGate->executeRest(
                    $organizationId,$sourceId->value(),
                    function() use($organizationId,$sourceId,$batch):array{
                        $now=$this->clock->now();
                        $previous=$this->sources->health($organizationId,$sourceId);
                        $lastEventAt=null;
                        foreach($batch->events as $raw){
                            if($lastEventAt===null||$raw->receivedAt>$lastEventAt){
                                $lastEventAt=$raw->receivedAt;
                            }
                        }
                        $this->sources->saveHealth($organizationId,new MarketSourceHealth(
                            $sourceId,MarketConnectionState::Active,$lastEventAt,$now,
                            0,0,$this->clock->reliable(),null,null,null,
                            $previous?->errorCount??0,$previous?->reconnectCount??0,
                            $previous?->rateLimitState??MarketRateLimitState::Unknown,
                        ));
                        $result=['raw'=>0,'accepted'=>0,'duplicates'=>0,'failed'=>0,'errors'=>[]];
                        foreach($batch->events as $raw){
                            $result['raw']++;
                            $ingested=$this->ingestion->ingest($organizationId,$raw);
                            if($ingested->status==='ACCEPTED'){$result['accepted']++;continue;}
                            if($ingested->status==='DUPLICATE'){$result['duplicates']++;continue;}
                            $result['failed']++;
                            if($ingested->reason!==null&&count($result['errors'])<10){
                                $result['errors'][]=$ingested->status.': '.$ingested->reason;
                            }
                        }
                        return $result;
                    },
                );
                $rawEvents+=$outcome['raw'];
                $accepted+=$outcome['accepted'];
                $duplicates+=$outcome['duplicates'];
                $failed+=$outcome['failed'];
                foreach($outcome['errors'] as $message){
                    if(count($errors)<10)$errors[]=$message;
                }
            }catch(MarketSourceStreamingActiveException){
                // This is healthy ownership arbitration, NOT a data-provider
                // failure. Do not overwrite streaming health as DEGRADED.
                return new MarketSourcePollResult(
                    'STREAMING_ACTIVE',$polled,$rawEvents,$accepted,$duplicates,$failed,$errors
                );
            }catch(Throwable $error){
                $failed++;
                try{
                    $this->writerGate->executeRest(
                        $organizationId,$sourceId->value(),
                        function()use($organizationId,$sourceId,$error):void{
                            $previous=$this->sources->health($organizationId,$sourceId);
                            $this->sources->saveHealth($organizationId,new MarketSourceHealth(
                                $sourceId,MarketConnectionState::Degraded,
                                $previous?->lastEventAt,$this->clock->now(),
                                ($previous?->failureCount??0)+1,$previous?->queueLag??0,
                                $this->clock->reliable(),
                                mb_substr($error->getMessage(),0,1000),
                                $previous?->messagesPerSecond,
                                $previous?->lastLatencyMilliseconds,
                                ($previous?->errorCount??0)+1,
                                $previous?->reconnectCount??0,
                                $previous?->rateLimitState??MarketRateLimitState::Unknown,
                            ));
                        },
                    );
                }catch(MarketSourceStreamingActiveException){
                    return new MarketSourcePollResult(
                        'STREAMING_ACTIVE',$polled,$rawEvents,$accepted,$duplicates,$failed-1,$errors
                    );
                }catch(Throwable $healthError){
                    if(count($errors)<10)$errors[]='Unable to persist polling source health.';
                }
                if(count($errors)<10)$errors[]=mb_substr($error->getMessage(),0,1000);
            }
        }

        $status=$failed===0?'OK':($accepted>0||$duplicates>0?'DEGRADED':'FAILED');
        return new MarketSourcePollResult($status,$polled,$rawEvents,$accepted,$duplicates,$failed,$errors);
    }

    private function capability(MarketEventType $type):MarketDataCapability
    {
        return match($type){
            MarketEventType::Quote,MarketEventType::Bbo=>MarketDataCapability::Bbo,
            MarketEventType::Trade=>MarketDataCapability::Trades,
            MarketEventType::OrderBookSnapshot,MarketEventType::OrderBookDelta=>MarketDataCapability::OrderBook,
            MarketEventType::Candle=>MarketDataCapability::Candles,
            MarketEventType::Volume=>MarketDataCapability::Volume,
            MarketEventType::FundingRate=>MarketDataCapability::Funding,
            MarketEventType::OpenInterest=>MarketDataCapability::OpenInterest,
            MarketEventType::ReferencePrice,MarketEventType::MarkPrice,MarketEventType::IndexPrice=>MarketDataCapability::ReferencePrice,
        };
    }
}
