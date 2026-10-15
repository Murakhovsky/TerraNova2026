<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Application\Service;
use DomainException;
use Domains\CapitalMarkets\Application\Contract\{
    MarketSourceRepositoryInterface,MarketSubscriptionRepositoryInterface,MarketInstrumentResolverInterface
};
use Domains\CapitalMarkets\Domain\MarketData\{MarketSourceId,MarketEventType};
use Domains\CapitalMarkets\Domain\Instrument\{InstrumentFamily,InstrumentStatus};
use Domains\CapitalMarkets\Domain\Venue\VenueInstrumentStatus;
use Domains\CapitalMarkets\Infrastructure\MarketData\Adapter\Bybit\BybitPerpetualMarketDataAdapter;

/** No shadow universe; all four capabilities must be canonical ACTIVE intents. */
final readonly class BybitDerivativesStreamScope
{
    public function __construct(
        private MarketSourceRepositoryInterface $sources,
        private MarketSubscriptionRepositoryInterface $subscriptions,
        private MarketInstrumentResolverInterface $instruments,
    ){}
    /** @return array{source:\Domains\CapitalMarkets\Domain\MarketData\MarketSourceDescriptor,
     * target:\Domains\CapitalMarkets\Domain\MarketData\MarketDataInstrumentTarget,
     * keys:list<string>,instrument_id:string,symbol:string} */
    public function resolve(string $org,string $sourceName):array
    {
        $sourceId=MarketSourceId::fromString($sourceName);
        $source=$this->sources->get($org,$sourceId);
        if($source===null||!$source->enabled||$source->venueId===null
            ||$source->adapterType!==BybitPerpetualMarketDataAdapter::ADAPTER_TYPE){
            throw new DomainException('Enabled Bybit Linear source required.');
        }
        $wanted=['FUNDING_RATE','MARK_PRICE','INDEX_PRICE','OPEN_INTEREST'];
        $seen=[];$instrument=null;$keys=[];
        foreach($this->subscriptions->forSource($org,$sourceId,true) as $sub){
            $kind=$sub->dataType->value;
            if(!in_array($kind,$wanted,true)||isset($seen[$kind])
                ||$sub->venueId===null||!$sub->venueId->equals($source->venueId)){
                throw new DomainException('Dedicated derivatives source requires four distinct subscriptions.');
            }
            $value=$sub->instrumentId->value();
            if($instrument!==null&&$instrument!==$value){
                throw new DomainException('Derivatives source cannot mix contract instruments.');
            }
            $instrument=$value;$seen[$kind]=true;$keys[]=StreamingSubscriptionPlanner::key($sub);
        }
        if(count($seen)!==4||count($keys)!==4||$instrument===null){
            throw new DomainException('Derivatives stream missing canonical mark/index/OI/funding intent.');
        }
        $subs=$this->subscriptions->forSource($org,$sourceId,true);
        $target=$this->instruments->target($org,$source,$subs[0]->instrumentId);
        if($target===null||$target->venueInstrument===null
            ||$target->instrument->family!==InstrumentFamily::Perpetual
            ||$target->instrument->status!==InstrumentStatus::Active
            ||$target->venueInstrument->status!==VenueInstrumentStatus::Active
            ||!$target->venueInstrument->venueId->equals($source->venueId)){
            throw new DomainException('Active canonical linear perpetual mapping unavailable.');
        }
        return ['source'=>$source,'target'=>$target,
            'keys'=>$keys,'instrument_id'=>$instrument,'symbol'=>strtoupper($target->externalSymbol)];
    }
}
