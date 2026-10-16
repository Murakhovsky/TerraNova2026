<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Infrastructure\MarketData\Adapter\Okx;

use Domains\CapitalMarkets\Application\Contract\MarketJsonHttpClientInterface;
use Domains\CapitalMarkets\Domain\Contract\{MarketClockInterface,MarketDataAdapterInterface};
use Domains\CapitalMarkets\Domain\MarketData\{
    MarketConnectionState,MarketDataBatch,MarketDataCapability,MarketDataInstrumentTarget,
    MarketSourceDescriptor,MarketSourceHealth,RawMarketEvent
};
use Domains\CapitalMarkets\Infrastructure\MarketData\Time\ProviderTimestamp;
use Domains\CapitalMarkets\Infrastructure\MarketData\VenueMarketStatusResolver;
use InvalidArgumentException;

/**
 * Public OKX v5 SPOT REST observation. Distinct venue-scoped exact instrument
 * mapping; never infer equivalent tokenized security from a similar ticker.
 */
final readonly class OkxSpotMarketDataAdapter implements MarketDataAdapterInterface
{
    public const ADAPTER_TYPE='okx.spot.v5';
    private const HOST='www.okx.com';
    public function __construct(
        private MarketJsonHttpClientInterface $http,
        private MarketClockInterface $clock,
        private OkxPublicPayloadParser $parser,
        private VenueMarketStatusResolver $status,
    ){}
    public function adapterType():string{return self::ADAPTER_TYPE;}
    public function getSource():string{return 'OKX';}
    public function getCapabilities():array{return [
        MarketDataCapability::Bbo,MarketDataCapability::Volume,MarketDataCapability::OrderBook,
    ];}
    public function supports(MarketDataCapability $cap,MarketDataInstrumentTarget $target):bool{
        return in_array($cap,$this->getCapabilities(),true)&&$target->venueInstrument!==null;
    }
    public function resolveInstrument(string $org,MarketSourceDescriptor $source,MarketDataInstrumentTarget $target):?string
    {
        if($source->adapterType!==self::ADAPTER_TYPE||$source->venueId===null
            ||$target->venueInstrument===null
            ||!$source->venueId->equals($target->venueInstrument->venueId))return null;
        $symbol=strtoupper($target->externalSymbol);
        // OKX spot instrument names have an explicit base-quote separator.
        return preg_match('/^[A-Z0-9]{2,30}-[A-Z0-9]{2,30}$/D',$symbol)===1?$symbol:null;
    }
    public function getSnapshot(string $org,MarketSourceDescriptor $source,MarketDataInstrumentTarget $target,array $caps):MarketDataBatch
    {
        $id=$this->resolveInstrument($org,$source,$target);
        if($id===null||$caps===[])throw new InvalidArgumentException('Unresolved OKX spot subscription.');
        $requested=[];
        foreach($caps as $cap){
            if(!$cap instanceof MarketDataCapability||!$this->supports($cap,$target)){
                throw new InvalidArgumentException('Unsupported OKX spot capability.');
            }
            $requested[$cap->value]=true;
        }
        $received=$this->clock->now();
        $market=$this->status->resolve($target->venueInstrument,$received)->value;
        $events=[];
        if(isset($requested[MarketDataCapability::Bbo->value])||isset($requested[MarketDataCapability::Volume->value])){
            $json=$this->http->get($org,'capital_markets.okx.spot.ticker',
                'https://'.self::HOST.'/api/v5/market/ticker?instId='.rawurlencode($id),[],[self::HOST]);
            $row=$this->parser->ticker($json,$id);
            if(($row['instType']??'SPOT')!=='SPOT')throw new InvalidArgumentException('OKX spot ticker type mismatch.');
            $at=ProviderTimestamp::fromMilliseconds($this->parser->unsigned($row['ts']??null,'ticker ts'));
            foreach([[MarketDataCapability::Bbo,'okx.spot.ticker.bbo'],
                     [MarketDataCapability::Volume,'okx.spot.ticker.volume']] as [$cap,$type]){
                if(!isset($requested[$cap->value]))continue;
                $events[]=new RawMarketEvent('cm-okx-spot-'.bin2hex(random_bytes(14)),
                    $source->id,$source->venueId,$id,$type,$at,$received,
                    null,['ticker_json'=>$json],
                    ['provider'=>'OKX','transport'=>'REST','market_status'=>$market]);
            }
        }
        if(isset($requested[MarketDataCapability::OrderBook->value])){
            $json=$this->http->get($org,'capital_markets.okx.spot.book',
                'https://'.self::HOST.'/api/v5/market/books?instId='.rawurlencode($id).'&sz=50',
                [],[self::HOST]);
            $book=$this->parser->book($json);
            $events[]=new RawMarketEvent('cm-okx-spot-'.bin2hex(random_bytes(14)),
                $source->id,$source->venueId,$id,'okx.spot.orderbook.snapshot',
                ProviderTimestamp::fromMilliseconds($book['timestamp']),$received,
                $book['sequence'],['book_json'=>$json],
                ['provider'=>'OKX','transport'=>'REST','market_status'=>$market]);
        }
        return new MarketDataBatch($source->id,$events);
    }
    public function getHealth(string $org,MarketSourceDescriptor $source):MarketSourceHealth
    {
        return new MarketSourceHealth($source->id,
            $source->enabled?MarketConnectionState::Connected:MarketConnectionState::Disabled,
            null,null,0,0,$this->clock->reliable());
    }
}
