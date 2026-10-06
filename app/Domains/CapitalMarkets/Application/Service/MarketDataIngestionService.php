<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use DateTimeImmutable;
use DomainException;
use Domains\CapitalMarkets\Application\Contract\CanonicalMarketEventRepositoryInterface;
use Domains\CapitalMarkets\Application\Contract\CapitalMarketsEventPublisherInterface;
use Domains\CapitalMarkets\Application\Contract\MarketDataNormalizerInterface;
use Domains\CapitalMarkets\Application\Contract\MarketPartitionLockInterface;
use Domains\CapitalMarkets\Application\Contract\MarketQualityMetricRepositoryInterface;
use Domains\CapitalMarkets\Application\Contract\MarketSourceRepositoryInterface;
use Domains\CapitalMarkets\Application\Contract\MarketStateRepositoryInterface;
use Domains\CapitalMarkets\Application\Contract\RawMarketEventRepositoryInterface;
use Domains\CapitalMarkets\Application\DTO\DecodedMarketEvent;
use Domains\CapitalMarkets\Application\DTO\MarketDataIngestionResult;
use Domains\CapitalMarkets\Application\Exception\MarketDataNormalizationException;
use Domains\CapitalMarkets\Domain\Contract\MarketClockInterface;
use Domains\CapitalMarkets\Domain\Event\MarketDataInstrumentUnresolved;
use Domains\CapitalMarkets\Domain\Event\MarketDataNormalizationFailed;
use Domains\CapitalMarkets\Domain\Event\MarketStateBecameDegraded;
use Domains\CapitalMarkets\Domain\Event\MarketStateBecameStale;
use Domains\CapitalMarkets\Domain\Event\MarketStateBecameTrusted;
use Domains\CapitalMarkets\Domain\Event\MarketStateBecameUntrusted;
use Domains\CapitalMarkets\Domain\MarketData\CanonicalMarketEvent;
use Domains\CapitalMarkets\Domain\MarketData\MarketConnectionState;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataQualityPolicy;
use Domains\CapitalMarkets\Domain\MarketData\MarketEventType;
use Domains\CapitalMarkets\Domain\MarketData\MarketSequencePolicy;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceDescriptor;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceHealth;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceRole;
use Domains\CapitalMarkets\Domain\MarketData\MarketState;
use Domains\CapitalMarkets\Domain\MarketData\MarketTrustStatus;
use Domains\CapitalMarkets\Domain\MarketData\RawMarketEvent;
use Domains\CapitalMarkets\Domain\MarketData\ReferenceMarketState;
use Domains\CapitalMarkets\Domain\Service\MarketDataQualityEngine;
use Domains\CapitalMarkets\Domain\Service\MarketStateEngine;
use Domains\CapitalMarkets\Domain\Service\ReferenceMarketStateEngine;
use Kernel\Transaction\Contract\TransactionManagerInterface;
use RuntimeException;
use Throwable;

final readonly class MarketDataIngestionService
{
    public function __construct(
        private MarketSourceRepositoryInterface $sources,
        private MarketDataDecoderRegistry $decoders,
        private MarketDataNormalizerInterface $normalizer,
        private RawMarketEventRepositoryInterface $rawEvents,
        private CanonicalMarketEventRepositoryInterface $canonicalEvents,
        private MarketQualityMetricRepositoryInterface $qualityMetrics,
        private MarketStateRepositoryInterface $states,
        private MarketDataQualityEngine $quality,
        private MarketStateEngine $marketStates,
        private ReferenceMarketStateEngine $referenceStates,
        private MarketPartitionLockInterface $partitionLock,
        private CapitalMarketsEventPublisherInterface $events,
        private TransactionManagerInterface $transactions,
        private MarketClockInterface $clock,
    ){}

    public function ingest(string $organizationId,RawMarketEvent $raw):MarketDataIngestionResult
    {
        $source=$this->sources->get($organizationId,$raw->sourceId);
        if($source===null)throw new DomainException('Market-data source is not configured.');
        if(!$source->id->equals($raw->sourceId))throw new DomainException('Raw event source identity mismatch.');

        $rawStored=$this->rawEvents->append($organizationId,$raw);

        if(!$source->enabled){
            return new MarketDataIngestionResult(
                'SOURCE_DISABLED',$rawStored,false,false,null,null,null,'Market-data source is disabled.'
            );
        }

        try{
            $decoded=$this->decoders->get($source->adapterType)->decode($source,$raw);
            $event=$this->normalizer->normalize($organizationId,$source,$raw,$decoded);
        }catch(MarketDataNormalizationException $error){
            $this->publishNormalizationFailure($organizationId,$raw,$error);
            return new MarketDataIngestionResult(
                $error->reasonCode==='UNKNOWN_INSTRUMENT'?'UNKNOWN_INSTRUMENT':'NORMALIZATION_FAILED',
                $rawStored,false,false,null,null,null,$error->getMessage()
            );
        }catch(Throwable $error){
            $wrapped=new MarketDataNormalizationException('DECODE_FAILED',$error->getMessage());
            $this->publishNormalizationFailure($organizationId,$raw,$wrapped);
            return new MarketDataIngestionResult(
                'DECODE_FAILED',$rawStored,false,false,null,null,null,$error->getMessage()
            );
        }

        $partition=$this->partitionKey($organizationId,$source,$event);
        return $this->partitionLock->synchronized(
            $partition,
            fn():MarketDataIngestionResult=>$this->applyCanonical($organizationId,$source,$decoded,$event,$rawStored),
        );
    }

    private function applyCanonical(
        string $organizationId,
        MarketSourceDescriptor $source,
        DecodedMarketEvent $decoded,
        CanonicalMarketEvent $event,
        bool $rawStored,
    ):MarketDataIngestionResult{
        return $this->transactions->transactional(function()use($organizationId,$source,$decoded,$event,$rawStored):MarketDataIngestionResult{
            $isReference=$this->referenceOnly($source);
            $previous=$isReference
                ?$this->states->getReference($organizationId,$source->id,$event->instrumentId)
                :($event->venueId===null?null:$this->states->get($organizationId,$event->venueId,$event->instrumentId));

            $health=$this->sources->health($organizationId,$source->id)??new MarketSourceHealth(
                $source->id,MarketConnectionState::Disconnected,null,null,0,0,$this->clock->reliable()
            );
            $policy=$source->qualityPolicy??$this->defaultQualityPolicy();

            $assessment=$this->quality->assess(
                $event,
                $previous,
                $health,
                $this->clock->now(),
                $policy,
                null,
            );

            $canonicalStored=$this->canonicalEvents->append($organizationId,$event);
            if(!$canonicalStored){
                return new MarketDataIngestionResult(
                    'DUPLICATE',$rawStored,false,false,$event,$assessment,$previous,'Canonical fingerprint already exists.'
                );
            }

            $this->qualityMetrics->append($organizationId,$event,$assessment,$this->clock->now());

            if($isReference){
                $next=$this->referenceStates->apply(
                    $event,$assessment,$previous instanceof ReferenceMarketState?$previous:null,
                    $decoded->session,$decoded->referenceType,$this->clock->now()
                );
                $stateUpdated=$previous!==$next;
                if($stateUpdated)$this->states->saveReference($organizationId,$next);
            }else{
                if($event->venueId===null)throw new RuntimeException('Trading market event is missing venue identity.');
                $next=$this->marketStates->apply(
                    $event,$assessment,$previous instanceof MarketState?$previous:null,$event->marketStatus
                );
                $stateUpdated=$previous!==$next;
                if($stateUpdated)$this->states->save($organizationId,$next);
            }

            if($stateUpdated){
                $this->publishTrustTransition($organizationId,$previous,$next,$event);
            }

            return new MarketDataIngestionResult(
                'ACCEPTED',$rawStored,true,$stateUpdated,$event,$assessment,$next
            );
        });
    }

    private function referenceOnly(MarketSourceDescriptor $source):bool
    {
        return $source->hasRole(MarketSourceRole::ReferenceSource)
            &&!$source->hasRole(MarketSourceRole::TradingSource);
    }

    private function partitionKey(string $organizationId,MarketSourceDescriptor $source,CanonicalMarketEvent $event):string
    {
        if($this->referenceOnly($source)){
            return $organizationId.'|reference|'.$source->id->value().'|'.$event->instrumentId->value();
        }
        return $organizationId.'|market|'.($event->venueId?->value()??'none').'|'.$event->instrumentId->value();
    }

    private function publishNormalizationFailure(
        string $organizationId,
        RawMarketEvent $raw,
        MarketDataNormalizationException $error,
    ):void{
        $publish=function()use($organizationId,$raw,$error):void{
            $now=$this->clock->now();
            $payload=[
                'source_id'=>$raw->sourceId->value(),
                'external_instrument'=>$raw->externalInstrument,
                'provider_event_type'=>$raw->eventType,
                'reason_code'=>$error->reasonCode,
                'message'=>$error->getMessage(),
            ];
            if($error->reasonCode==='UNKNOWN_INSTRUMENT'){
                $this->events->publish(new MarketDataInstrumentUnresolved(
                    $this->eventId(),$now,$organizationId,$raw->eventId,$payload
                ));
                return;
            }
            $this->events->publish(new MarketDataNormalizationFailed(
                $this->eventId(),$now,$organizationId,$raw->eventId,$payload
            ));
        };

        if($this->transactions->isActive()){$publish();return;}
        $this->transactions->transactional($publish);
    }

    private function publishTrustTransition(
        string $organizationId,
        MarketState|ReferenceMarketState|null $previous,
        MarketState|ReferenceMarketState $next,
        CanonicalMarketEvent $event,
    ):void{
        $before=$previous?->quality->status;
        $after=$next->quality->status;
        if($before===$after)return;

        $key=$next instanceof MarketState
            ?$next->key()
            :$next->sourceId->value().'|'.$next->instrumentId->value();

        $payload=[
            'source_id'=>$event->sourceId->value(),
            'venue_id'=>$event->venueId?->value(),
            'instrument_id'=>$event->instrumentId->value(),
            'event_type'=>$event->eventType()->value,
            'previous_trust_status'=>$before?->value,
            'trust_status'=>$after->value,
            'quality_score'=>$next->quality->score,
            'flags'=>array_map(static fn($flag):string=>$flag->value,$next->quality->flags),
            'state_version'=>$next->stateVersion,
        ];

        $domainEvent=match($after){
            MarketTrustStatus::Trusted=>new MarketStateBecameTrusted($this->eventId(),$this->clock->now(),$organizationId,$key,$payload),
            MarketTrustStatus::Stale=>new MarketStateBecameStale($this->eventId(),$this->clock->now(),$organizationId,$key,$payload),
            MarketTrustStatus::Untrusted,MarketTrustStatus::Unavailable
                =>new MarketStateBecameUntrusted($this->eventId(),$this->clock->now(),$organizationId,$key,$payload),
            MarketTrustStatus::Degraded=>new MarketStateBecameDegraded($this->eventId(),$this->clock->now(),$organizationId,$key,$payload),
        };
        $this->events->publish($domainEvent);
    }

    private function defaultQualityPolicy():MarketDataQualityPolicy
    {
        $ages=[];
        foreach(MarketEventType::cases() as $type){
            $ages[$type->value]=match($type){
                MarketEventType::Trade=>5000,
                MarketEventType::Candle,MarketEventType::Volume,MarketEventType::FundingRate,MarketEventType::OpenInterest=>60000,
                MarketEventType::ReferencePrice=>5000,
                default=>2000,
            };
        }
        return new MarketDataQualityPolicy(
            $ages,500,1000,500,1000,500,false,
            [
                MarketEventType::OrderBookSnapshot->value=>MarketSequencePolicy::Monotonic,
                MarketEventType::OrderBookDelta->value=>MarketSequencePolicy::Contiguous,
            ],
        );
    }

    private function eventId():string
    {
        return 'cm-'.bin2hex(random_bytes(16));
    }
}
