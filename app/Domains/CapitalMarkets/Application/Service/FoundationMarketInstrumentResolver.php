<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use Domains\CapitalMarkets\Application\Contract\MarketInstrumentResolverInterface;
use Domains\CapitalMarkets\Application\DTO\ResolvedMarketInstrument;
use Domains\CapitalMarkets\Domain\Contract\InstrumentRepository;
use Domains\CapitalMarkets\Domain\Contract\VenueRepository;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentIdentifier;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentIdentifierType;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceDescriptor;

final readonly class FoundationMarketInstrumentResolver implements MarketInstrumentResolverInterface
{
    public function __construct(
        private InstrumentRepository $instruments,
        private VenueRepository $venues,
    ){}

    public function resolve(string $organizationId,MarketSourceDescriptor $source,string $externalInstrument):?ResolvedMarketInstrument
    {
        $externalInstrument=trim($externalInstrument);
        if($externalInstrument==='')return null;

        if($source->venueId!==null){
            foreach($this->venues->instruments($organizationId,$source->venueId) as $mapping){
                if(strcasecmp($mapping->venueSymbol,$externalInstrument)!==0)continue;
                $instrument=$this->instruments->get($organizationId,$mapping->instrumentId);
                return $instrument===null?null:new ResolvedMarketInstrument($instrument,$mapping);
            }
            return null;
        }

        foreach([
            new InstrumentIdentifier(InstrumentIdentifierType::ProviderId,$externalInstrument,$source->id->value()),
            new InstrumentIdentifier(InstrumentIdentifierType::Ticker,$externalInstrument,$source->id->value()),
            new InstrumentIdentifier(InstrumentIdentifierType::Ticker,$externalInstrument,null),
        ] as $identifier){
            $instrument=$this->instruments->findByIdentifier($organizationId,$identifier);
            if($instrument!==null)return new ResolvedMarketInstrument($instrument,null);
        }

        foreach($this->instruments->list($organizationId,['q'=>$externalInstrument],100) as $instrument){
            if(strcasecmp($instrument->symbol,$externalInstrument)===0||strcasecmp($instrument->canonicalSymbol,$externalInstrument)===0){
                return new ResolvedMarketInstrument($instrument,null);
            }
        }
        return null;
    }
}
