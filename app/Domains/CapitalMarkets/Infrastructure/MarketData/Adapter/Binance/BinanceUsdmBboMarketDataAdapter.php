<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\Adapter\Binance;

use Domains\CapitalMarkets\Application\Contract\MarketJsonHttpClientInterface;
use Domains\CapitalMarkets\Domain\Contract\MarketClockInterface;
use Domains\CapitalMarkets\Domain\Contract\MarketDataAdapterInterface;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentFamily;
use Domains\CapitalMarkets\Domain\MarketData\MarketConnectionState;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataBatch;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataCapability;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataInstrumentTarget;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceDescriptor;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceHealth;
use Domains\CapitalMarkets\Domain\MarketData\RawMarketEvent;
use Domains\CapitalMarkets\Infrastructure\MarketData\Time\ProviderTimestamp;
use Domains\CapitalMarkets\Infrastructure\MarketData\VenueMarketStatusResolver;
use InvalidArgumentException;

/**
 * Public USDⓈ-M futures best-bid-offer only.
 *
 * The contract's funding, mark/index price and risk metadata are deliberately
 * NOT fabricated by this adapter. This is not the derivatives profile API.
 * Source must be mapped to an actual USDⓈ-M venue instrument.
 */
final readonly class BinanceUsdmBboMarketDataAdapter implements MarketDataAdapterInterface
{
    public const ADAPTER_TYPE='binance.usdm.perpetual.bbo.v1';
    private const HOST='fapi.binance.com';
    private const BASE='https://fapi.binance.com/fapi/v1/ticker/bookTicker?symbol=';

    public function __construct(
        private MarketJsonHttpClientInterface $http,
        private MarketClockInterface $clock,
        private BinanceSpotPayloadParser $parser,
        private VenueMarketStatusResolver $status,
    ){}

    public function adapterType():string{return self::ADAPTER_TYPE;}
    public function getSource():string{return 'BINANCE_USDM';}
    public function getCapabilities():array{return [MarketDataCapability::Bbo];}

    public function supports(MarketDataCapability $capability,MarketDataInstrumentTarget $target):bool
    {
        return $capability===MarketDataCapability::Bbo && $target->venueInstrument!==null
            && $target->instrument->family===InstrumentFamily::Perpetual;
    }

    public function resolveInstrument(
        string $organizationId,MarketSourceDescriptor $source,MarketDataInstrumentTarget $target,
    ):?string{
        if($source->adapterType!==self::ADAPTER_TYPE||$source->venueId===null
            ||$target->venueInstrument===null
            ||$target->instrument->family!==InstrumentFamily::Perpetual
            ||!$target->venueInstrument->venueId->equals($source->venueId))return null;
        $symbol=strtoupper($target->externalSymbol);
        return preg_match('/^[A-Z0-9]{3,40}$/D',$symbol)===1?$symbol:null;
    }

    public function getSnapshot(
        string $organizationId,MarketSourceDescriptor $source,MarketDataInstrumentTarget $target,array $capabilities,
    ):MarketDataBatch{
        $symbol=$this->resolveInstrument($organizationId,$source,$target);
        if($symbol===null||$source->venueId===null||$capabilities!==[MarketDataCapability::Bbo]){
            throw new InvalidArgumentException('USD-M BBO adapter needs exactly one mapped BBO capability.');
        }
        $body=$this->http->get(
            $organizationId,'capital_markets.binance.usdm.bbo',self::BASE.rawurlencode($symbol),
            [],[self::HOST],
        );
        $this->parser->bbo($body,$symbol);
        try{$decoded=json_decode($body,true,32,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);}
        catch(\JsonException){throw new InvalidArgumentException('Invalid Binance USD-M REST ticker.');}
        $time=$decoded['time']??null;
        if((!is_int($time)&&!is_string($time))||!ctype_digit((string)$time)){
            throw new InvalidArgumentException('Binance USD-M REST transaction timestamp missing.');
        }
        $received=$this->clock->now();
        $providerAt=ProviderTimestamp::fromMilliseconds($time);
        $market=$this->status->resolve($target->venueInstrument,$received)->value;
        return new MarketDataBatch($source->id,[
            new RawMarketEvent(
                'cm-binance-usdm-rest-bbo-'.bin2hex(random_bytes(12)),
                $source->id,$source->venueId,$symbol,'binance.usdm.ticker.bbo',
                $providerAt,$received,null,['raw_json'=>$body],
                ['provider'=>'BINANCE_USDM','transport'=>'REST',
                 'endpoint'=>'/fapi/v1/ticker/bookTicker','market_status'=>$market],
            ),
        ]);
    }

    public function getHealth(string $organizationId,MarketSourceDescriptor $source):MarketSourceHealth
    {
        return new MarketSourceHealth(
            $source->id,$source->enabled?MarketConnectionState::Connected:MarketConnectionState::Disabled,
            null,null,0,0,$this->clock->reliable(),
        );
    }
}
