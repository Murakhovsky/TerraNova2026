<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\Adapter\Bybit;

use Domains\CapitalMarkets\Application\Contract\MarketJsonHttpClientInterface;
use Domains\CapitalMarkets\Domain\Contract\MarketClockInterface;
use Domains\CapitalMarkets\Domain\Contract\MarketDataAdapterInterface;
use Domains\CapitalMarkets\Domain\MarketData\MarketConnectionState;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataBatch;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataCapability;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataInstrumentTarget;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceDescriptor;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceHealth;
use Domains\CapitalMarkets\Domain\MarketData\RawMarketEvent;
use Domains\CapitalMarkets\Infrastructure\MarketData\Time\ProviderTimestamp;
use InvalidArgumentException;

final readonly class BybitSpotMarketDataAdapter implements MarketDataAdapterInterface
{
    public const ADAPTER_TYPE='bybit.spot.v5';
    private const HOST='api.bybit.com';
    private const BASE_URL='https://api.bybit.com';

    public function __construct(
        private MarketJsonHttpClientInterface $http,
        private MarketClockInterface $clock,
        private BybitTickerPayloadParser $parser,
        private BybitOrderBookPayloadParser $orderBooks,
        private \Domains\CapitalMarkets\Infrastructure\MarketData\VenueMarketStatusResolver $status,
    ){}

    public function adapterType():string{return self::ADAPTER_TYPE;}
    public function getSource():string{return 'BYBIT';}

    public function getCapabilities():array
    {
        return [MarketDataCapability::Bbo,MarketDataCapability::Volume,MarketDataCapability::OrderBook];
    }

    public function supports(MarketDataCapability $capability,MarketDataInstrumentTarget $target):bool
    {
        return in_array($capability,$this->getCapabilities(),true)&&$target->venueInstrument!==null;
    }

    public function resolveInstrument(
        string $organizationId,
        MarketSourceDescriptor $source,
        MarketDataInstrumentTarget $target,
    ):?string{
        if($source->adapterType!==self::ADAPTER_TYPE||$source->venueId===null||$target->venueInstrument===null)return null;
        if(!$target->venueInstrument->venueId->equals($source->venueId))return null;
        $symbol=strtoupper($target->externalSymbol);
        if(preg_match('/^[A-Z0-9][A-Z0-9._:-]{1,119}$/',$symbol)!==1)return null;
        return $symbol;
    }

    public function getSnapshot(
        string $organizationId,
        MarketSourceDescriptor $source,
        MarketDataInstrumentTarget $target,
        array $capabilities,
    ):MarketDataBatch{
        $symbol=$this->resolveInstrument($organizationId,$source,$target);
        if($symbol===null)throw new InvalidArgumentException('Bybit target is not mapped to the configured venue.');

        $url=self::BASE_URL.'/v5/market/tickers?category=spot&symbol='.rawurlencode($symbol);
        $body=$this->http->get(
            $organizationId,
            'capital_markets.bybit.spot.ticker',
            $url,
            [],
            [self::HOST],
        );
        $ticker=$this->parser->parse($body,$symbol);
        $receivedAt=$this->clock->now();
        $providerAt=ProviderTimestamp::fromMilliseconds($ticker['time']);
        $rawPayload=['raw_json'=>$body];
        $marketStatus=$this->status->resolve($target->venueInstrument,$receivedAt)->value;

        $events=[];
        $requested=$this->requested($capabilities,$target);
        if(isset($requested[MarketDataCapability::Bbo->value])){
            $events[]=new RawMarketEvent(
                $this->eventId('bbo'),$source->id,$source->venueId,$symbol,'bybit.spot.ticker.bbo',
                $providerAt,$receivedAt,null,$rawPayload,
                ['provider'=>'BYBIT','transport'=>'REST','endpoint'=>'/v5/market/tickers','market_status'=>$marketStatus],
            );
        }
        if(isset($requested[MarketDataCapability::Volume->value])){
            $events[]=new RawMarketEvent(
                $this->eventId('volume'),$source->id,$source->venueId,$symbol,'bybit.spot.ticker.volume',
                $providerAt,$receivedAt,null,$rawPayload,
                ['provider'=>'BYBIT','transport'=>'REST','endpoint'=>'/v5/market/tickers','market_status'=>$marketStatus],
            );
        }

        if(isset($requested[MarketDataCapability::OrderBook->value])){
            $bookBody=$this->http->get(
                $organizationId,
                'capital_markets.bybit.spot.orderbook',
                self::BASE_URL.'/v5/market/orderbook?category=spot&symbol='.rawurlencode($symbol).'&limit=50',
                [],
                [self::HOST],
            );
            $book=$this->orderBooks->parse($bookBody,$symbol);
            $events[]=new RawMarketEvent(
                $this->eventId('book'),$source->id,$source->venueId,$symbol,'bybit.spot.orderbook.snapshot',
                ProviderTimestamp::fromMilliseconds($book['time']),$receivedAt,$book['sequence'],['raw_json'=>$bookBody],
                ['provider'=>'BYBIT','transport'=>'REST','endpoint'=>'/v5/market/orderbook','market_status'=>$marketStatus],
            );
        }

        return new MarketDataBatch($source->id,$events);
    }

    public function getHealth(string $organizationId,MarketSourceDescriptor $source):MarketSourceHealth
    {
        return new MarketSourceHealth(
            $source->id,
            $source->enabled?MarketConnectionState::Connected:MarketConnectionState::Disabled,
            null,
            null,
            0,
            0,
            $this->clock->reliable(),
        );
    }

    /** @param list<MarketDataCapability> $capabilities @return array<string,true> */
    private function requested(array $capabilities,MarketDataInstrumentTarget $target):array
    {
        if($capabilities===[])throw new InvalidArgumentException('Bybit snapshot requires at least one capability.');
        $requested=[];
        foreach($capabilities as $capability){
            if(!$capability instanceof MarketDataCapability||!$this->supports($capability,$target)){
                throw new InvalidArgumentException('Unsupported Bybit snapshot capability.');
            }
            $requested[$capability->value]=true;
        }
        return $requested;
    }

    private function eventId(string $kind):string
    {
        return 'cm-raw-bybit-'.$kind.'-'.bin2hex(random_bytes(12));
    }
}
