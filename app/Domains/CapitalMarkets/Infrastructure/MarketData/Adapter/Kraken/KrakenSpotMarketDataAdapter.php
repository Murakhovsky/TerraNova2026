<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\Adapter\Kraken;

use DateTimeImmutable;
use Domains\CapitalMarkets\Application\Contract\MarketJsonHttpClientInterface;
use Domains\CapitalMarkets\Domain\Contract\MarketClockInterface;
use Domains\CapitalMarkets\Domain\Contract\MarketDataAdapterInterface;
use Domains\CapitalMarkets\Domain\MarketData\{MarketConnectionState,MarketDataBatch,MarketDataCapability,MarketDataInstrumentTarget,MarketSourceDescriptor,MarketSourceHealth,RawMarketEvent};
use Domains\CapitalMarkets\Infrastructure\MarketData\VenueMarketStatusResolver;
use InvalidArgumentException;

final readonly class KrakenSpotMarketDataAdapter implements MarketDataAdapterInterface
{
    public const ADAPTER_TYPE='kraken.spot.rest';
    private const HOST='api.kraken.com';
    private const BASE='https://api.kraken.com';

    public function __construct(
        private MarketJsonHttpClientInterface $http,
        private MarketClockInterface $clock,
        private KrakenSpotPayloadParser $parser,
        private VenueMarketStatusResolver $status,
    ){}
    public function adapterType():string{return self::ADAPTER_TYPE;}
    public function getSource():string{return 'KRAKEN';}
    public function getCapabilities():array{return [MarketDataCapability::Bbo,MarketDataCapability::Volume,MarketDataCapability::OrderBook];}
    public function supports(MarketDataCapability $c,MarketDataInstrumentTarget $t):bool{return in_array($c,$this->getCapabilities(),true)&&$t->venueInstrument!==null;}
    public function resolveInstrument(string $o,MarketSourceDescriptor $s,MarketDataInstrumentTarget $t):?string
    {
        if($s->adapterType!==self::ADAPTER_TYPE||$s->venueId===null||$t->venueInstrument===null||!$t->venueInstrument->venueId->equals($s->venueId))return null;
        $symbol=trim($t->externalSymbol);return preg_match('/^[A-Za-z0-9._:\/-]{3,120}$/',$symbol)===1?$symbol:null;
    }
    public function getSnapshot(string $o,MarketSourceDescriptor $s,MarketDataInstrumentTarget $t,array $caps):MarketDataBatch
    {
        $symbol=$this->resolveInstrument($o,$s,$t); if($symbol===null)throw new InvalidArgumentException('Kraken target mapping invalid.');
        if($caps===[])throw new InvalidArgumentException('Kraken snapshot requires capability.');
        $requested=[];foreach($caps as $c){if(!$c instanceof MarketDataCapability||!$this->supports($c,$t))throw new InvalidArgumentException('Unsupported Kraken capability.');$requested[$c->value]=true;}
        $received=$this->clock->now();$status=$this->status->resolve($t->venueInstrument,$received)->value;$events=[];
        if(isset($requested[MarketDataCapability::Bbo->value])||isset($requested[MarketDataCapability::Volume->value])){
            $body=$this->http->get($o,'capital_markets.kraken.spot.ticker',self::BASE.'/0/public/Ticker?pair='.rawurlencode($symbol),[],[self::HOST]);
            $this->parser->ticker($body);
            if(isset($requested[MarketDataCapability::Bbo->value]))$events[]=new RawMarketEvent($this->id('bbo'),$s->id,$s->venueId,$symbol,'kraken.spot.ticker.bbo',$received,$received,null,['raw_json'=>$body],['provider'=>'KRAKEN','transport'=>'REST','market_status'=>$status]);
            if(isset($requested[MarketDataCapability::Volume->value]))$events[]=new RawMarketEvent($this->id('volume'),$s->id,$s->venueId,$symbol,'kraken.spot.ticker.volume',$received,$received,null,['raw_json'=>$body],['provider'=>'KRAKEN','transport'=>'REST','market_status'=>$status]);
        }
        if(isset($requested[MarketDataCapability::OrderBook->value])){
            $body=$this->http->get($o,'capital_markets.kraken.spot.depth',self::BASE.'/0/public/Depth?pair='.rawurlencode($symbol).'&count=50',[],[self::HOST]);
            $depth=$this->parser->depth($body);$provider=$depth['timestamp']>0?(new DateTimeImmutable('@'.$depth['timestamp'])):$received;
            $events[]=new RawMarketEvent($this->id('book'),$s->id,$s->venueId,$symbol,'kraken.spot.depth.snapshot',$provider,$received,null,['raw_json'=>$body],['provider'=>'KRAKEN','transport'=>'REST','market_status'=>$status]);
        }
        return new MarketDataBatch($s->id,$events);
    }
    public function getHealth(string $o,MarketSourceDescriptor $s):MarketSourceHealth{return new MarketSourceHealth($s->id,$s->enabled?MarketConnectionState::Connected:MarketConnectionState::Disabled,null,null,0,0,$this->clock->reliable());}
    private function id(string $k):string{return 'cm-raw-kraken-'.$k.'-'.bin2hex(random_bytes(12));}
}
