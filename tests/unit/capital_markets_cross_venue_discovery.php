<?php
declare(strict_types=1);

use Domains\CapitalMarkets\Application\Contract\MarketJsonHttpClientInterface;
use Domains\CapitalMarkets\Application\Service\CrossVenueUniverse;
use Domains\CapitalMarkets\Application\Service\CrossVenueCatalogDiscovery;
use Domains\CapitalMarkets\Infrastructure\MarketData\Adapter\Binance\BinanceSpotPayloadParser;

require dirname(__DIR__,2).'/vendor/autoload.php';

$assert=static function(bool $ok,string $message):void{
    if(!$ok)throw new RuntimeException($message);
};
$universe=(new CrossVenueUniverse())->load(
    dirname(__DIR__,2).'/resources/capital-markets/universes/binance-bstocks-2026-09-27.csv'
);
$assert(count($universe)===80,'Historical bStocks universe must retain all 80 unique instruments.');
$assert(count(array_filter($universe,static fn(array $c):bool=>$c['risk_class']==='LEVERAGED_OR_INVERSE'))===9,
    'Nine leveraged/inverse instruments must remain explicitly marked.');
$aapl=array_values(array_filter($universe,static fn(array $c):bool=>$c['underlying']==='AAPL'))[0]??null;
$assert($aapl!==null && $aapl['token']==='AAPLB' && $aapl['market']==='AAPLBUSDT',
    'bStock token cannot silently become AAPLx.');

$parser=new BinanceSpotPayloadParser();
$bbo=$parser->bbo('{"symbol":"AAPLBUSDT","bidPrice":"182.12","bidQty":"7","askPrice":"182.13","askQty":"6"}','AAPLBUSDT');
$assert($bbo['bid_price']==='182.12' && $bbo['ask_price']==='182.13','Binance BBO must preserve decimal strings.');
$depth=$parser->depth('{"lastUpdateId":123,"bids":[["182.12","5"]],"asks":[["182.13","2"]]}');
$assert($depth['sequence']===123 && $depth['bids'][0]['price']==='182.12','Binance depth evidence is malformed.');
$assert($parser->volume('{"symbol":"AAPLBUSDT","volume":"100.5"}','AAPLBUSDT')['value']==='100.5',
    'Binance volume must retain an exact decimal.');
foreach ([
    fn()=> $parser->bbo('{"symbol":"AAPLXUSDT","bidPrice":"1","bidQty":"1","askPrice":"2","askQty":"1"}','AAPLBUSDT'),
    fn()=> $parser->bbo('{"symbol":"AAPLBUSDT","bidPrice":1.0,"bidQty":"1","askPrice":"2","askQty":"1"}','AAPLBUSDT'),
    fn()=> $parser->bbo('{"code":-1121,"msg":"Invalid symbol."}','AAPLBUSDT'),
    fn()=> $parser->depth('{"lastUpdateId":-1,"bids":[],"asks":[]}'),
] as $reject) {
    try {$reject();throw new RuntimeException('Unsafe Binance payload accepted.');}
    catch (InvalidArgumentException) {}
}

final class CrossVenueDiscoveryFakeClient implements MarketJsonHttpClientInterface
{
    /** @var array<string,string> */ public array $data=[];
    public int $calls=0;
    public function get(string $organizationId,string $serviceKey,string $url,array $headers,array $allowedHosts):string
    {
        ++$this->calls;
        if ($organizationId !== 'tenant-alpha' || !in_array((string)parse_url($url,PHP_URL_HOST),$allowedHosts,true)
            || $headers!==[])throw new RuntimeException('Unexpected discovery HTTP authority.');
        return $this->data[$serviceKey]??throw new RuntimeException('Provider offline.');
    }
}
$http=new CrossVenueDiscoveryFakeClient();
$http->data=[
    'capital_markets.discovery.binance'=>json_encode(['symbols'=>[
        ['symbol'=>'AAPLBUSDT','baseAsset'=>'AAPLB','quoteAsset'=>'USDT','status'=>'TRADING'],
        ['symbol'=>'NVDABUSDT','baseAsset'=>'NVDAB','quoteAsset'=>'USDT','status'=>'BREAK'],
    ]],JSON_THROW_ON_ERROR),
    'capital_markets.discovery.bybit'=>json_encode(['retCode'=>0,'result'=>['list'=>[
        ['symbol'=>'AAPLXUSDT','baseCoin'=>'AAPLX','quoteCoin'=>'USDT','status'=>'Trading'],
        ['symbol'=>'AAPLBUSDT','baseCoin'=>'AAPLB','quoteCoin'=>'USDT','status'=>'PreLaunch'],
    ]]],JSON_THROW_ON_ERROR),
    'capital_markets.discovery.kraken'=>json_encode(['error'=>[],'result'=>[
        'AAPLXUSD'=>['altname'=>'AAPLXUSD','wsname'=>'AAPLX/USD','status'=>'online'],
        'DELISTED'=>['altname'=>'AAPLBUSD','wsname'=>'AAPLB/USD','status'=>'cancel_only'],
    ]],JSON_THROW_ON_ERROR),
];
$scanner=new CrossVenueCatalogDiscovery($http);
$result=$scanner->scan('tenant-alpha',[$aapl]);
$assert($http->calls===3 && $result['total']===1,'Discovery must query each catalog once per scan.');
$assert($result['rows'][0]['venues'][0]['status']==='LISTED_EXACT_TOKEN_SYMBOL',
    'Binance must confirm exact listed symbol from public catalog.');
$assert($result['rows'][0]['venues'][1]['status']==='DISTINCT_TOKEN_REVIEW_REQUIRED'
    && $result['rows'][0]['venues'][1]['equivalence']==='NOT_EQUIVALENT',
    'Bybit AAPLx must not be merged with Binance AAPLB.');
$assert($result['rows'][0]['venues'][2]['status']==='DISTINCT_TOKEN_REVIEW_REQUIRED',
    'Kraken xStock must remain a distinct token.');
$assert($result['rows'][0]['economic_link']==='UNVERIFIED'
    && $result['rows'][0]['executable']===false
    && $result['rows'][0]['net_edge']===null,
    'Listing discovery must not publish executable investment recommendations or fake P&L.');
unset($http->data['capital_markets.discovery.kraken']);
$degraded=$scanner->scan('tenant-alpha',[$aapl]);
$assert($degraded['source_health']['KRAKEN']['state']==='UNAVAILABLE'
    && $degraded['rows'][0]['venues'][2]['status']==='UNAVAILABLE',
    'Provider outage must not be rendered as not listed.');
echo "Cross-venue 80-row historical universe, Binance market payload and discovery policy passed.\n";
