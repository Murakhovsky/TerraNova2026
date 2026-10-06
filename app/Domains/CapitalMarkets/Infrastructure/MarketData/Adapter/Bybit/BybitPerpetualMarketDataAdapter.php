<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\Adapter\Bybit;

use DateTimeImmutable;
use Domains\CapitalMarkets\Application\Contract\MarketJsonHttpClientInterface;
use Domains\CapitalMarkets\Domain\Contract\DerivativeMarketDataAdapterInterface;
use Domains\CapitalMarkets\Domain\Contract\MarketClockInterface;
use Domains\CapitalMarkets\Domain\Instrument\ContractType;
use Domains\CapitalMarkets\Domain\Instrument\MarginMode;
use Domains\CapitalMarkets\Domain\Instrument\PerpetualProfile;
use Domains\CapitalMarkets\Domain\MarketData\FundingRateObservation;
use Domains\CapitalMarkets\Domain\MarketData\FundingRateStatus;
use Domains\CapitalMarkets\Domain\MarketData\FundingRateType;
use Domains\CapitalMarkets\Domain\MarketData\MarketConnectionState;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataBatch;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataCapability;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataInstrumentTarget;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceDescriptor;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceHealth;
use Domains\CapitalMarkets\Domain\MarketData\RawMarketEvent;
use Domains\CapitalMarkets\Domain\Value\AssetCode;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Infrastructure\MarketData\Time\ProviderTimestamp;
use Domains\CapitalMarkets\Infrastructure\MarketData\VenueMarketStatusResolver;
use InvalidArgumentException;

final readonly class BybitPerpetualMarketDataAdapter implements DerivativeMarketDataAdapterInterface
{
    public const ADAPTER_TYPE='bybit.linear-perpetual.v5';
    private const HOST='api.bybit.com';
    private const BASE_URL='https://api.bybit.com';

    public function __construct(
        private MarketJsonHttpClientInterface $http,
        private MarketClockInterface $clock,
        private BybitPerpetualTickerPayloadParser $tickerParser,
        private BybitPerpetualInstrumentPayloadParser $instrumentParser,
        private BybitFundingHistoryPayloadParser $fundingHistoryParser,
        private BybitOrderBookPayloadParser $orderBooks,
        private VenueMarketStatusResolver $status,
    ){}

    public function adapterType():string{return self::ADAPTER_TYPE;}
    public function getSource():string{return 'BYBIT';}

    public function getCapabilities():array
    {
        return [
            MarketDataCapability::Bbo,MarketDataCapability::Volume,MarketDataCapability::OrderBook,
            MarketDataCapability::Funding,MarketDataCapability::OpenInterest,
            MarketDataCapability::MarkPrice,MarketDataCapability::IndexPrice,
            MarketDataCapability::DerivativesMetadata,
        ];
    }

    public function supports(MarketDataCapability $capability,MarketDataInstrumentTarget $target):bool
    {
        return in_array($capability,$this->getCapabilities(),true)&&$target->venueInstrument!==null;
    }

    public function resolveInstrument(string $organizationId,MarketSourceDescriptor $source,MarketDataInstrumentTarget $target):?string
    {
        if($source->adapterType!==self::ADAPTER_TYPE||$source->venueId===null||$target->venueInstrument===null)return null;
        if(!$target->venueInstrument->venueId->equals($source->venueId))return null;
        $symbol=strtoupper($target->externalSymbol);
        return preg_match('/^[A-Z0-9][A-Z0-9._:-]{1,119}$/',$symbol)===1?$symbol:null;
    }

    public function getSnapshot(
        string $organizationId,MarketSourceDescriptor $source,MarketDataInstrumentTarget $target,array $capabilities
    ):MarketDataBatch{
        $symbol=$this->resolveInstrument($organizationId,$source,$target);
        if($symbol===null)throw new InvalidArgumentException('Bybit perpetual target is not mapped to the configured venue.');
        $requested=$this->requested($capabilities,$target);
        $receivedAt=$this->clock->now();
        $marketStatus=$this->status->resolve($target->venueInstrument,$receivedAt)->value;
        $events=[];

        $tickerBody=$this->http->get(
            $organizationId,'capital_markets.bybit.linear.ticker',
            self::BASE_URL.'/v5/market/tickers?category=linear&symbol='.rawurlencode($symbol),[],[self::HOST],
        );
        $ticker=$this->tickerParser->parse($tickerBody,$symbol);
        $providerAt=ProviderTimestamp::fromMilliseconds($ticker['time']);

        $instrumentBody=null;
        if(isset($requested[MarketDataCapability::Funding->value])||isset($requested[MarketDataCapability::DerivativesMetadata->value])){
            $instrumentBody=$this->instrumentInfoBody($organizationId,$symbol);
        }

        $definitions=[
            MarketDataCapability::Bbo->value=>'bybit.linear.ticker.bbo',
            MarketDataCapability::Volume->value=>'bybit.linear.ticker.volume',
            MarketDataCapability::Funding->value=>'bybit.linear.ticker.funding',
            MarketDataCapability::OpenInterest->value=>'bybit.linear.ticker.open_interest',
            MarketDataCapability::MarkPrice->value=>'bybit.linear.ticker.mark_price',
            MarketDataCapability::IndexPrice->value=>'bybit.linear.ticker.index_price',
        ];
        foreach($definitions as $capability=>$eventType){
            if(!isset($requested[$capability]))continue;
            $payload=['ticker_json'=>$tickerBody];
            if($capability===MarketDataCapability::Funding->value)$payload['instrument_json']=$instrumentBody;
            $events[]=new RawMarketEvent(
                $this->eventId($capability),$source->id,$source->venueId,$symbol,$eventType,
                $providerAt,$receivedAt,null,$payload,
                ['provider'=>'BYBIT','transport'=>'REST','endpoint'=>'/v5/market/tickers','market_status'=>$marketStatus],
            );
        }

        if(isset($requested[MarketDataCapability::DerivativesMetadata->value])){
            $events[]=new RawMarketEvent(
                $this->eventId('metadata'),$source->id,$source->venueId,$symbol,'bybit.linear.instrument.metadata',
                $providerAt,$receivedAt,null,['instrument_json'=>$instrumentBody],
                ['provider'=>'BYBIT','transport'=>'REST','endpoint'=>'/v5/market/instruments-info','market_status'=>$marketStatus],
            );
        }

        if(isset($requested[MarketDataCapability::OrderBook->value])){
            $body=$this->http->get(
                $organizationId,'capital_markets.bybit.linear.orderbook',
                self::BASE_URL.'/v5/market/orderbook?category=linear&symbol='.rawurlencode($symbol).'&limit=50',[],[self::HOST],
            );
            $book=$this->orderBooks->parse($body,$symbol);
            $events[]=new RawMarketEvent(
                $this->eventId('book'),$source->id,$source->venueId,$symbol,'bybit.linear.orderbook.snapshot',
                ProviderTimestamp::fromMilliseconds($book['time']),$receivedAt,$book['sequence'],['raw_json'=>$body],
                ['provider'=>'BYBIT','transport'=>'REST','endpoint'=>'/v5/market/orderbook','market_status'=>$marketStatus],
            );
        }

        return new MarketDataBatch($source->id,$events);
    }

    public function getPerpetualProfile(
        string $organizationId,MarketSourceDescriptor $source,MarketDataInstrumentTarget $target
    ):PerpetualProfile{
        $symbol=$this->resolveInstrument($organizationId,$source,$target);
        if($symbol===null)throw new InvalidArgumentException('Bybit perpetual target is unresolved.');
        $row=$this->instrumentParser->parse($this->instrumentInfoBody($organizationId,$symbol),$symbol);
        $contractType=$row['contract_type']==='LinearPerpetual'?ContractType::Linear:ContractType::Other;
        return new PerpetualProfile(
            new AssetCode($row['base_coin']),$contractType,new AssetCode($row['settle_coin']),new AssetCode($row['settle_coin']),
            Decimal::fromString('1'),Decimal::fromString('1'),
            $this->instrumentParser->precision($row['tick_size']),$this->instrumentParser->precision($row['quantity_step']),
            Decimal::fromString($row['min_quantity']),Decimal::fromString($row['minimum_notional']),
            Decimal::fromString($row['max_leverage']),[MarginMode::Isolated,MarginMode::Cross],true,
            $row['funding_interval_minutes']*60,'BYBIT_MARK_PRICE','BYBIT_INDEX_PRICE','BYBIT_V5_LINEAR',
            ['symbol'=>$symbol,'status'=>$row['status'],'upper_funding_rate'=>$row['upper_funding_rate'],'lower_funding_rate'=>$row['lower_funding_rate']],
        );
    }

    public function getFundingHistory(
        string $organizationId,MarketSourceDescriptor $source,MarketDataInstrumentTarget $target,
        DateTimeImmutable $from,DateTimeImmutable $to,int $limit=200,
    ):array{
        if($limit<1||$limit>200||$to<$from)throw new InvalidArgumentException('Invalid Bybit funding history range.');
        $symbol=$this->resolveInstrument($organizationId,$source,$target);
        if($symbol===null||$source->venueId===null)throw new InvalidArgumentException('Bybit perpetual target is unresolved.');
        $profile=$this->getPerpetualProfile($organizationId,$source,$target);
        $url=self::BASE_URL.'/v5/market/funding/history?category=linear&symbol='.rawurlencode($symbol)
            .'&startTime='.($from->getTimestamp()*1000).'&endTime='.($to->getTimestamp()*1000).'&limit='.$limit;
        $body=$this->http->get($organizationId,'capital_markets.bybit.linear.funding_history',$url,[],[self::HOST]);
        $rows=$this->fundingHistoryParser->parse($body,$symbol);
        $out=[];
        foreach($rows as $row){
            $out[]=new FundingRateObservation(
                $source->venueId,$target->instrument->id,Decimal::fromString($row['rate']),
                FundingRateType::NormalizedLongsPayShorts,ProviderTimestamp::fromMilliseconds($row['timestamp']),
                null,$profile->fundingIntervalSeconds??throw new InvalidArgumentException('Bybit funding interval unavailable.'),
                isset($profile->venueMetadata['upper_funding_rate'])?Decimal::fromString((string)$profile->venueMetadata['upper_funding_rate']):null,
                isset($profile->venueMetadata['lower_funding_rate'])?Decimal::fromString((string)$profile->venueMetadata['lower_funding_rate']):null,
                'BYBIT_SETTLED_FUNDING',100,FundingRateStatus::Settled,
            );
        }
        return $out;
    }

    public function getHealth(string $organizationId,MarketSourceDescriptor $source):MarketSourceHealth
    {
        return new MarketSourceHealth(
            $source->id,$source->enabled?MarketConnectionState::Connected:MarketConnectionState::Disabled,
            null,null,0,0,$this->clock->reliable(),
        );
    }

    /** @param list<MarketDataCapability> $capabilities @return array<string,true> */
    private function requested(array $capabilities,MarketDataInstrumentTarget $target):array
    {
        if($capabilities===[])throw new InvalidArgumentException('Bybit perpetual snapshot requires at least one capability.');
        $out=[];
        foreach($capabilities as $capability){
            if(!$capability instanceof MarketDataCapability||!$this->supports($capability,$target))throw new InvalidArgumentException('Unsupported Bybit perpetual capability.');
            $out[$capability->value]=true;
        }
        return $out;
    }

    private function instrumentInfoBody(string $organizationId,string $symbol):string
    {
        return $this->http->get(
            $organizationId,'capital_markets.bybit.linear.instrument_info',
            self::BASE_URL.'/v5/market/instruments-info?category=linear&symbol='.rawurlencode($symbol),[],[self::HOST],
        );
    }

    private function eventId(string $kind):string{return 'cm-raw-bybit-linear-'.strtolower(str_replace('_','-',$kind)).'-'.bin2hex(random_bytes(12));}
}
