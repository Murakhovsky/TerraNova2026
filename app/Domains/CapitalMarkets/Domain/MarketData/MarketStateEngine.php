<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

use DomainException;
use InvalidArgumentException;

final readonly class MarketStateEngine
{
    public function __construct(private MarketDataQualityEngine $quality){}

    public function apply(
        ?MarketState $current,
        CanonicalMarketEvent $event,
        MarketHealthPolicy $policy,
        MarketConnectionState $connectionState,
        MarketClock $clock,
    ):MarketStateTransition{
        if($event->venueId===null)throw new InvalidArgumentException('Trading MarketState requires venue identity.');
        if($current!==null){
            if(!$current->venueId->equals($event->venueId)||!$current->instrumentId->equals($event->instrumentId)){
                throw new DomainException('Market event does not belong to the current market state.');
            }
        }

        $type=$event->eventType->value;
        $fingerprint=$event->fingerprint();
        if(($current?->eventFingerprints[$type]??null)===$fingerprint){
            return new MarketStateTransition($current,false,false,[MarketQualityFlag::Duplicate]);
        }

        $flags=[];
        $previousWatermark=$current?->eventWatermarks[$type]??null;
        if($previousWatermark!==null&&$event->sourceTimestamp->format('U.u')<$previousWatermark){
            return new MarketStateTransition($current,false,false,[MarketQualityFlag::OutOfOrder]);
        }

        $previousSequence=$current?->sequenceWatermarks[$type]??null;
        if($event->sequence!==null&&$previousSequence!==null){
            if($event->sequenceSemantics===SequenceSemantics::Monotonic
                &&self::compareUnsigned($event->sequence,$previousSequence)<=0){
                return new MarketStateTransition($current,false,false,[MarketQualityFlag::OutOfOrder]);
            }
            if($event->sequenceSemantics===SequenceSemantics::Contiguous
                &&$event->sequence!==self::incrementUnsigned($previousSequence)){
                $flags[] = MarketQualityFlag::SequenceGap;
                if($event->eventType===MarketEventType::OrderBookDelta){
                    $flags[] = MarketQualityFlag::OrderBookInvalid;
                    return $this->qualityOnlyTransition($current,$event,$policy,$connectionState,$clock,$flags);
                }
            }
        }

        $quote=$current?->quote;
        $lastTrade=$current?->lastTrade;
        $orderBook=$current?->orderBook;
        $volume=$current?->volume;

        if(in_array($event->eventType,[MarketEventType::Quote,MarketEventType::Bbo],true)){
            if(!$event->payload instanceof MarketQuote)throw new DomainException('Quote event payload is invalid.');
            $quote=$event->payload;
        }elseif($event->eventType===MarketEventType::Trade){
            if(!$event->payload instanceof MarketTrade)throw new DomainException('Trade event payload is invalid.');
            $lastTrade=$event->payload;
        }elseif($event->eventType===MarketEventType::OrderBookSnapshot){
            if(!$event->payload instanceof MarketOrderBook||!$event->payload->snapshot){
                throw new DomainException('Order-book snapshot event payload is invalid.');
            }
            $orderBook=$event->payload;
        }elseif($event->eventType===MarketEventType::OrderBookDelta){
            if(!$event->payload instanceof MarketOrderBook||$event->payload->snapshot){
                throw new DomainException('Order-book delta event payload is invalid.');
            }
            if($orderBook===null){
                $flags[] = MarketQualityFlag::OrderBookInvalid;
                return $this->qualityOnlyTransition($current,$event,$policy,$connectionState,$clock,$flags);
            }
            $orderBook=$orderBook->applyDelta($event->payload);
        }elseif($event->eventType===MarketEventType::Volume){
            if(!$event->payload instanceof MarketVolume)throw new DomainException('Volume event payload is invalid.');
            $volume=$event->payload;
        }

        $assessment=$this->quality->assess($event,$policy,$connectionState,$clock,$flags);
        $watermarks=$current?->eventWatermarks??[];
        $watermarks[$type]=$event->sourceTimestamp->format('U.u');
        $sequences=$current?->sequenceWatermarks??[];
        if($event->sequence!==null)$sequences[$type]=$event->sequence;
        $fingerprints=$current?->eventFingerprints??[];
        $fingerprints[$type]=$fingerprint;

        $state=new MarketState(
            $event->sourceId,$event->venueId,$event->instrumentId,
            $quote,$lastTrade,$orderBook,$volume,
            $event->marketStatus,$event->mode,
            $event->sourceTimestamp,$event->normalizedTimestamp,$assessment,
            ($current?->stateVersion??0)+1,$watermarks,$sequences,$fingerprints,
        );
        return new MarketStateTransition($state,true,true,$flags);
    }

    /** @param list<MarketQualityFlag> $flags */
    private function qualityOnlyTransition(
        ?MarketState $current,
        CanonicalMarketEvent $event,
        MarketHealthPolicy $policy,
        MarketConnectionState $connectionState,
        MarketClock $clock,
        array $flags,
    ):MarketStateTransition{
        $assessment=$this->quality->assess($event,$policy,$connectionState,$clock,$flags);
        if($current===null){
            $state=new MarketState(
                $event->sourceId,$event->venueId??throw new DomainException('Venue is required.'),$event->instrumentId,
                null,null,null,null,$event->marketStatus,$event->mode,
                $event->sourceTimestamp,$event->normalizedTimestamp,$assessment,1,
            );
        }else{
            $state=new MarketState(
                $current->sourceId,$current->venueId,$current->instrumentId,
                $current->quote,$current->lastTrade,
                in_array(MarketQualityFlag::OrderBookInvalid,$flags,true)?null:$current->orderBook,
                $current->volume,$current->marketStatus,$current->mode,
                $current->sourceTimestamp,$event->normalizedTimestamp,$assessment,$current->stateVersion+1,
                $current->eventWatermarks,$current->sequenceWatermarks,$current->eventFingerprints,
            );
        }
        return new MarketStateTransition($state,false,true,$flags);
    }

    private static function compareUnsigned(string $left,string $right):int
    {
        if(preg_match('/^[0-9]+$/',$left)!==1||preg_match('/^[0-9]+$/',$right)!==1){
            throw new InvalidArgumentException('Sequence values must be unsigned integers.');
        }
        $left=ltrim($left,'0')?:'0';$right=ltrim($right,'0')?:'0';
        $length=strlen($left)<=>strlen($right);
        return $length!==0?$length:(strcmp($left,$right)<=>0);
    }

    private static function incrementUnsigned(string $value):string
    {
        if(preg_match('/^[0-9]+$/',$value)!==1)throw new InvalidArgumentException('Sequence value must be an unsigned integer.');
        $digits=str_split($value);$carry=1;
        for($i=count($digits)-1;$i>=0&&$carry===1;$i--){
            $next=((int)$digits[$i])+1;
            $digits[$i]=(string)($next%10);
            $carry=$next>=10?1:0;
        }
        if($carry===1)array_unshift($digits,'1');
        return ltrim(implode('',$digits),'0')?:'0';
    }
}
