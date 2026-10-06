<?php
declare(strict_types=1);

use DateTimeImmutable;
use Domains\CapitalMarkets\Application\Contract\MarketJsonHttpClientInterface;
use Domains\CapitalMarkets\Application\Service\MarketDataAdapterRegistry;
use Domains\CapitalMarkets\Application\Service\MarketDataDecoderRegistry;
use Domains\CapitalMarkets\Domain\Contract\MarketClockInterface;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentDescriptor;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentFamily;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentId;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentStatus;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataCapability;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataInstrumentTarget;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataMode;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataQualityPolicy;
use Domains\CapitalMarkets\Domain\MarketData\MarketEventType;
use Domains\CapitalMarkets\Domain\MarketData\MarketHealthPolicy;
use Domains\CapitalMarkets\Domain\MarketData\MarketSequencePolicy;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceDescriptor;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceId;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceRole;
use Domains\CapitalMarkets\Domain\MarketData\MarketStatus;
use Domains\CapitalMarkets\Domain\MarketData\RateLimitPolicy;
use Domains\CapitalMarkets\Domain\MarketData\ReconnectPolicy;
use Domains\CapitalMarkets\Domain\Value\AssetCode;
use Domains\CapitalMarkets\Domain\Venue\VenueId;
use Domains\CapitalMarkets\Domain\Venue\VenueInstrument;
use Domains\CapitalMarkets\Domain\Venue\VenueInstrumentStatus;
use Domains\CapitalMarkets\Infrastructure\MarketData\Adapter\Bybit\BybitMarketDataDecoder;
use Domains\CapitalMarkets\Infrastructure\MarketData\Adapter\Bybit\BybitOrderBookPayloadParser;
use Domains\CapitalMarkets\Infrastructure\MarketData\Adapter\Bybit\BybitSpotMarketDataAdapter;
use Domains\CapitalMarkets\Infrastructure\MarketData\Adapter\Bybit\BybitTickerPayloadParser;
use Domains\CapitalMarkets\Infrastructure\MarketData\Adapter\Kraken\KrakenMarketDataDecoder;
use Domains\CapitalMarkets\Infrastructure\MarketData\Adapter\Kraken\KrakenSpotMarketDataAdapter;
use Domains\CapitalMarkets\Infrastructure\MarketData\Adapter\Kraken\KrakenSpotPayloadParser;
use Domains\CapitalMarkets\Infrastructure\MarketData\Adapter\Massive\MassiveMarketDataDecoder;
use Domains\CapitalMarkets\Infrastructure\MarketData\Adapter\Massive\MassiveQuotePayloadParser;
use Domains\CapitalMarkets\Infrastructure\MarketData\Adapter\Massive\MassiveStocksReferenceAdapter;
use Domains\CapitalMarkets\Infrastructure\MarketData\Security\MarketSourceCredentialResolver;
use Domains\CapitalMarkets\Infrastructure\MarketData\VenueMarketStatusResolver;
use Domains\CapitalMarkets\Infrastructure\MarketData\Time\ProviderTimestamp;
use Platform\Integration\Contract\CredentialVaultInterface;
use Platform\Integration\Model\Credential;

require dirname(__DIR__,2).'/vendor/autoload.php';

$assert=static function(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
};

final class CmProviderClock implements MarketClockInterface
{
    public function __construct(private DateTimeImmutable $time){}
    public function now():DateTimeImmutable{return $this->time;}
    public function reliable():bool{return true;}
}

final class CmProviderHttp implements MarketJsonHttpClientInterface
{
    /** @var list<array{organization:string,service:string,url:string,headers:array,hosts:array}> */
    public array $calls=[];

    /** @param array<string,string> $responses */
    public function __construct(private array $responses){}

    public function get(string $organizationId,string $serviceKey,string $url,array $headers,array $allowedHosts):string
    {
        $this->calls[]=[
            'organization'=>$organizationId,'service'=>$serviceKey,'url'=>$url,
            'headers'=>$headers,'hosts'=>$allowedHosts,
        ];
        foreach($this->responses as $needle=>$response){
            if(str_contains($url,$needle))return $response;
        }
        throw new RuntimeException('No fixture response for provider URL.');
    }
}

final class CmProviderVault implements CredentialVaultInterface
{
    public ?Credential $lastCredential=null;
    public function resolve(Credential $credential):array
    {
        $this->lastCredential=$credential;
        return ['api_key'=>'fixture-massive-secret'];
    }
}

$organization='org-market-fixture';
$clock=new CmProviderClock(new DateTimeImmutable('2026-10-06T08:13:20.500000+00:00'));

$bybitBody='{"retCode":0,"retMsg":"OK","result":{"category":"spot","list":[{"symbol":"AAPLXUSDT","bid1Price":"293.48","bid1Size":"12.5","ask1Price":"293.61","ask1Size":"10","lastPrice":"293.55","volume24h":"12500.25"}]},"time":1760000000123}';
$bybitBookBody='{"retCode":0,"retMsg":"OK","result":{"s":"AAPLXUSDT","a":[["293.61","10"],["293.70","8"]],"b":[["293.48","12.5"],["293.40","7"]],"ts":1760000000124,"u":230704,"seq":1432604333,"cts":1760000000122},"retExtInfo":{},"time":1760000000125}';
$krakenTicker='{"error":[],"result":{"AAPLxUSD":{"a":["293.62","1","10.0"],"b":["293.50","1","11.0"],"c":["293.55","1"],"v":["5000","12000.5"]}}}';
$krakenDepth='{"error":[],"result":{"AAPLxUSD":{"asks":[["293.62","10","1760000000"],["293.70","8","1760000000"]],"bids":[["293.50","11","1760000000"],["293.40","7","1760000000"]]}}}';
$massiveBody='{"request_id":"fixture","results":{"P":293.360000000123,"S":4,"T":"AAPL","X":19,"p":293.320000000123,"q":83480742,"s":7,"t":1760000000123456789,"x":11,"y":1760000000123000000,"z":3},"status":"OK"}';

$http=new CmProviderHttp([
    '/v5/market/tickers?category=spot&symbol=AAPLXUSDT'=>$bybitBody,
    '/v5/market/orderbook?category=spot&symbol=AAPLXUSDT&limit=50'=>$bybitBookBody,
    '/0/public/Ticker?pair=AAPLx%2FUSD'=>$krakenTicker,
    '/0/public/Depth?pair=AAPLx%2FUSD&count=50'=>$krakenDepth,
    '/v2/last/nbbo/AAPL'=>$massiveBody,
]);
$vault=new CmProviderVault();

$tokenId=InstrumentId::fromString('instrument:aaplx');
$venueId=VenueId::fromString('venue:bybit');
$token=new InstrumentDescriptor(
    $tokenId,'AAPLX','AAPLX','Apple xStock',InstrumentFamily::TokenizedSecurity,InstrumentStatus::Active,
    null,new AssetCode('USDT'),null,null,$venueId->value(),[],
    new DateTimeImmutable('2026-10-01T00:00:00+00:00'),new DateTimeImmutable('2026-10-01T00:00:00+00:00')
);
$marketHours=['market_hours_timezone'=>'UTC','market_hours'=>[['days'=>[1,2,3,4,5],'open'=>'00:00','close'=>'24:00']]];
$venueMapping=new VenueInstrument($venueId,$tokenId,'AAPLXUSDT',VenueInstrumentStatus::Active,4,8,null,null,$marketHours);
$bybitTarget=new MarketDataInstrumentTarget($token,$venueMapping,'AAPLXUSDT');
$bybitSource=new MarketSourceDescriptor(
    MarketSourceId::fromString('source:bybit-xstocks'),$venueId,BybitSpotMarketDataAdapter::ADAPTER_TYPE,true,10,
    [MarketSourceRole::TradingSource],null,new RateLimitPolicy(120,1),new ReconnectPolicy(),new MarketHealthPolicy(),
    [],'PUBLIC-LIVE'
);

$statusResolver=new VenueMarketStatusResolver();
$bybitParser=new BybitTickerPayloadParser();
$bybitOrderBooks=new BybitOrderBookPayloadParser();
$bybitAdapter=new BybitSpotMarketDataAdapter($http,$clock,$bybitParser,$bybitOrderBooks,$statusResolver);
$bybitBatch=$bybitAdapter->getSnapshot(
    $organization,$bybitSource,$bybitTarget,[MarketDataCapability::Bbo,MarketDataCapability::Volume,MarketDataCapability::OrderBook]
);
$assert(count($bybitBatch->events)===3,'Bybit snapshot must preserve BBO, 24h volume and orderbook observations.');
$assert($bybitBatch->events[0]->providerTimestamp?->format('U.u')==='1760000000.123000','Bybit millisecond timestamp conversion drifted.');
$assert($bybitBatch->events[0]->mode===MarketDataMode::Live,'Bybit snapshot must remain LIVE.');
$assert($bybitAdapter->supports(MarketDataCapability::Bbo,$bybitTarget),'Bybit BBO capability missing.');
$assert($bybitAdapter->supports(MarketDataCapability::Volume,$bybitTarget),'Bybit volume capability missing.');
$assert($bybitAdapter->supports(MarketDataCapability::OrderBook,$bybitTarget),'Bybit orderbook capability missing.');
$bybitBboOnly=$bybitAdapter->getSnapshot($organization,$bybitSource,$bybitTarget,[MarketDataCapability::Bbo]);
$assert(count($bybitBboOnly->events)===1&&$bybitBboOnly->events[0]->eventType==='bybit.spot.ticker.bbo','Bybit snapshot leaked unsubscribed Volume data.');

$bybitDecoder=new BybitMarketDataDecoder($bybitParser,$bybitOrderBooks);
$decodedBbo=$bybitDecoder->decode($bybitSource,$bybitBatch->events[0]);
$decodedVolume=$bybitDecoder->decode($bybitSource,$bybitBatch->events[1]);
$decodedBook=$bybitDecoder->decode($bybitSource,$bybitBatch->events[2]);
$assert($decodedBbo->eventType===MarketEventType::Bbo,'Bybit BBO decoder type drifted.');
$assert(($decodedBbo->values['bid_price']??null)==='293.48','Bybit bid price lost provider decimal string.');
$assert(($decodedBbo->values['ask_quantity']??null)==='10','Bybit ask quantity lost provider decimal string.');
$assert($decodedVolume->eventType===MarketEventType::Volume,'Bybit volume decoder type drifted.');
$assert(($decodedVolume->values['value']??null)==='12500.25','Bybit 24h volume lost provider decimal string.');
$assert($decodedBbo->marketStatus===MarketStatus::Open,'Configured Bybit market hours must produce OPEN market status.');
$assert($decodedBook->eventType===MarketEventType::OrderBookSnapshot,'Bybit orderbook decoder type drifted.');
$assert(($decodedBook->values['bids'][0]['price']??null)==='293.48','Bybit orderbook bid lost provider decimal string.');

$krakenVenueId=VenueId::fromString('venue:kraken');
$krakenTokenId=InstrumentId::fromString('instrument:aaplx-kraken');
$krakenToken=new InstrumentDescriptor(
    $krakenTokenId,'AAPLX','AAPLX','Apple xStock Kraken',InstrumentFamily::TokenizedSecurity,InstrumentStatus::Active,
    null,new AssetCode('USD'),null,null,$krakenVenueId->value(),[],
    new DateTimeImmutable('2026-10-01T00:00:00+00:00'),new DateTimeImmutable('2026-10-01T00:00:00+00:00')
);
$krakenMapping=new VenueInstrument($krakenVenueId,$krakenTokenId,'AAPLx/USD',VenueInstrumentStatus::Active,4,8,null,null,$marketHours);
$krakenTarget=new MarketDataInstrumentTarget($krakenToken,$krakenMapping,'AAPLx/USD');
$krakenSource=new MarketSourceDescriptor(
    MarketSourceId::fromString('source:kraken-xstocks'),$krakenVenueId,KrakenSpotMarketDataAdapter::ADAPTER_TYPE,true,15,
    [MarketSourceRole::TradingSource],null,new RateLimitPolicy(60,1),new ReconnectPolicy(),new MarketHealthPolicy(),
    [],'PUBLIC-LIVE'
);
$krakenParser=new KrakenSpotPayloadParser();
$krakenAdapter=new KrakenSpotMarketDataAdapter($http,$clock,$krakenParser,$statusResolver);
$krakenBatch=$krakenAdapter->getSnapshot(
    $organization,$krakenSource,$krakenTarget,[MarketDataCapability::Bbo,MarketDataCapability::Volume,MarketDataCapability::OrderBook]
);
$assert(count($krakenBatch->events)===3,'Kraken snapshot must emit BBO, volume and orderbook.');
$krakenDecoder=new KrakenMarketDataDecoder($krakenParser);
$krakenBbo=$krakenDecoder->decode($krakenSource,$krakenBatch->events[0]);
$krakenVolume=$krakenDecoder->decode($krakenSource,$krakenBatch->events[1]);
$krakenBook=$krakenDecoder->decode($krakenSource,$krakenBatch->events[2]);
$assert($krakenBbo->eventType===MarketEventType::Bbo,'Kraken BBO decoder type drifted.');
$assert(($krakenBbo->values['bid_price']??null)==='293.50','Kraken bid price lost provider decimal string.');
$assert(($krakenBbo->values['ask_quantity']??null)==='10.0','Kraken ask size lost provider decimal string.');
$assert($krakenBbo->marketStatus===MarketStatus::Open,'Configured Kraken xStocks market hours must produce OPEN market status.');
$assert($krakenVolume->eventType===MarketEventType::Volume&&($krakenVolume->values['value']??null)==='12000.5','Kraken volume decode drifted.');
$assert($krakenBook->eventType===MarketEventType::OrderBookSnapshot,'Kraken depth must decode as orderbook snapshot.');
$assert(($krakenBook->values['asks'][0]['price']??null)==='293.62','Kraken orderbook ask lost provider decimal string.');

$unknownMapping=new VenueInstrument($krakenVenueId,$krakenTokenId,'AAPLx/USD',VenueInstrumentStatus::Active,4,8);
$assert($statusResolver->resolve($unknownMapping,$clock->now())===MarketStatus::Unknown,'Missing market-hours configuration must fail closed.');

$referenceId=InstrumentId::fromString('instrument:aapl');
$reference=new InstrumentDescriptor(
    $referenceId,'AAPL','AAPL','Apple Inc.',InstrumentFamily::Equity,InstrumentStatus::Active,
    null,new AssetCode('USD'),null,'US',null,[],
    new DateTimeImmutable('2026-10-01T00:00:00+00:00'),new DateTimeImmutable('2026-10-01T00:00:00+00:00')
);
$massiveTarget=new MarketDataInstrumentTarget($reference,null,'AAPL');
$ages=[];
foreach(MarketEventType::cases() as $type)$ages[$type->value]=1_200_000;
$massiveQuality=new MarketDataQualityPolicy(
    $ages,1000,1000,500,5000,1000,false,
    [MarketEventType::Bbo->value=>MarketSequencePolicy::Monotonic]
);
$massiveSource=new MarketSourceDescriptor(
    MarketSourceId::fromString('source:massive-us-stocks'),null,MassiveStocksReferenceAdapter::ADAPTER_TYPE,true,20,
    [MarketSourceRole::ReferenceSource],'env://CM_MASSIVE_FIXTURE',
    new RateLimitPolicy(5,60),new ReconnectPolicy(),new MarketHealthPolicy(),
    ['data_mode'=>'DELAYED'],'MASSIVE-STOCKS-DELAYED',$massiveQuality
);

$massiveParser=new MassiveQuotePayloadParser();
$credentialResolver=new MarketSourceCredentialResolver($vault);
$massiveAdapter=new MassiveStocksReferenceAdapter($http,$credentialResolver,$clock,$massiveParser);
$massiveBatch=$massiveAdapter->getSnapshot(
    $organization,$massiveSource,$massiveTarget,[MarketDataCapability::Bbo]
);
$assert(count($massiveBatch->events)===1,'Massive NBBO snapshot must emit exactly one raw quote event.');
$massiveRaw=$massiveBatch->events[0];
$assert($massiveRaw->sequence==='83480742','Massive quote sequence was not preserved.');
$assert($massiveRaw->providerTimestamp?->format('U.u')==='1760000000.123456','Massive nanosecond timestamp conversion drifted.');
$assert($massiveRaw->mode===MarketDataMode::Delayed,'Massive source data mode must be explicit.');

$massiveDecoder=new MassiveMarketDataDecoder($massiveParser);
$decodedMassive=$massiveDecoder->decode($massiveSource,$massiveRaw);
$assert($decodedMassive->eventType===MarketEventType::Bbo,'Massive NBBO decoder type drifted.');
$assert(($decodedMassive->values['bid_price']??null)==='293.320000000123','Massive bid price was rounded through binary float.');
$assert(($decodedMassive->values['ask_price']??null)==='293.360000000123','Massive ask price was rounded through binary float.');
$assert(($decodedMassive->values['bid_quantity']??null)==='7','Massive bid size drifted.');
$assert($massiveQuality->sequencePolicyFor(MarketEventType::Bbo)===MarketSequencePolicy::Monotonic,'Massive quotes must not require contiguous sequence numbers.');

$massiveCall=$http->calls[count($http->calls)-1];
$assert(!str_contains($massiveCall['url'],'apiKey')&&!str_contains($massiveCall['url'],'fixture-massive-secret'),'Massive API key leaked into request URL.');
$assert(in_array('Authorization: Bearer fixture-massive-secret',$massiveCall['headers'],true),'Massive Bearer authorization header missing.');
$assert($vault->lastCredential?->organizationId->value()===$organization,'Massive credential resolution lost tenant identity.');
$assert(in_array('capital_markets.market_data.read',$vault->lastCredential?->scopes??[],true),'Massive credential scope drifted.');

$adapters=new MarketDataAdapterRegistry([$bybitAdapter,$krakenAdapter,$massiveAdapter]);
$decoders=new MarketDataDecoderRegistry([$bybitDecoder,$krakenDecoder,$massiveDecoder]);
$assert($adapters->types()===[BybitSpotMarketDataAdapter::ADAPTER_TYPE,KrakenSpotMarketDataAdapter::ADAPTER_TYPE,MassiveStocksReferenceAdapter::ADAPTER_TYPE],'Provider adapter registry drifted.');
$assert($decoders->get(BybitSpotMarketDataAdapter::ADAPTER_TYPE)===$bybitDecoder,'Bybit decoder registry lookup failed.');
$assert($decoders->get(KrakenSpotMarketDataAdapter::ADAPTER_TYPE)===$krakenDecoder,'Kraken decoder registry lookup failed.');
$assert($decoders->get(MassiveStocksReferenceAdapter::ADAPTER_TYPE)===$massiveDecoder,'Massive decoder registry lookup failed.');

$assert(ProviderTimestamp::fromMilliseconds('1760000000123')->format('U.u')==='1760000000.123000','Millisecond timestamp helper drifted.');
$assert(ProviderTimestamp::fromNanoseconds('1760000000123456789')->format('U.u')==='1760000000.123456','Nanosecond timestamp helper drifted.');

echo "Capital Markets provider REST adapters passed.\n";
