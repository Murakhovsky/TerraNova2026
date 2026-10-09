<?php
declare(strict_types=1);

use Domains\CapitalMarkets\Application\Contract\MarketJsonHttpClientInterface;
use Domains\CapitalMarkets\Application\Service\CrossVenueQuoteSampler;

require dirname(__DIR__,2).'/vendor/autoload.php';

$assert=static function(bool $yes,string $message):void {
    if(!$yes)throw new RuntimeException($message);
};
final class QuoteTestHttp implements MarketJsonHttpClientInterface
{
    /** @var array<string,string> */ public array $fixtures=[];
    /** @var list<string> */ public array $urls=[];
    public function get(string $organizationId,string $serviceKey,string $url,array $headers,array $allowedHosts):string
    {
        if($organizationId!=='org-a'||$headers!==[]||
            !in_array((string)parse_url($url,PHP_URL_HOST),$allowedHosts,true)){
            throw new RuntimeException('Unsafe quote API boundary.');
        }
        $this->urls[]=$url;
        return $this->fixtures[$serviceKey]??throw new RuntimeException('Provider unavailable.');
    }
}
$client=new QuoteTestHttp();
$client->fixtures=[
    'capital_markets.quote_preview.binance'=>json_encode([
        ['symbol'=>'AAPLBUSDT','bidPrice'=>'181.01','bidQty'=>'4.25','askPrice'=>'181.02','askQty'=>'2.15'],
        ['symbol'=>'INVALIDUSDT','bidPrice'=>'1000','bidQty'=>'1','askPrice'=>'1001','askQty'=>'1']
    ],JSON_THROW_ON_ERROR),
    'capital_markets.quote_preview.bybit'=>json_encode(['retCode'=>0,'result'=>['list'=>[
        ['symbol'=>'AAPLXUSDT','bid1Price'=>'180.98','bid1Size'=>'3','ask1Price'=>'180.99','ask1Size'=>'6'],
    ]]],JSON_THROW_ON_ERROR),
    'capital_markets.quote_preview.kraken'=>json_encode(['error'=>[],'result'=>[
        'AAPLXUSD_INTERNAL'=>['b'=>['180.90','1','2'],'a'=>['181.10','1','3']],
    ]],JSON_THROW_ON_ERROR),
];
$discovery=[
    'dataset'=>'cross_venue_candidate_discovery','universe'=>'BINANCE_BSTOCKS_2026_09_27',
    'as_of_utc'=>(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format(DATE_ATOM),
    'rows'=>[[
        'candidate'=>['underlying'=>'AAPL','token'=>'AAPLB','market'=>'AAPLBUSDT','asset_type'=>'Stock','leverage'=>'1','risk_class'=>'STANDARD_CANDIDATE'],
        'venues'=>[
            ['venue'=>'BINANCE','status'=>'LISTED_EXACT_TOKEN_SYMBOL','symbol'=>'AAPLBUSDT','quote'=>'USDT','equivalence'=>'UNVERIFIED'],
            ['venue'=>'BYBIT','status'=>'DISTINCT_TOKEN_REVIEW_REQUIRED','symbol'=>'AAPLXUSDT','quote'=>'USDT','equivalence'=>'NOT_EQUIVALENT'],
            ['venue'=>'KRAKEN','status'=>'DISTINCT_TOKEN_REVIEW_REQUIRED','symbol'=>'AAPLXUSD','quote'=>'USD','pair_key'=>'AAPLXUSD_INTERNAL','equivalence'=>'NOT_EQUIVALENT'],
        ],
    ]],
];
$result=(new CrossVenueQuoteSampler($client))->observe('org-a',$discovery);
$assert(count($client->urls)===3,'One bounded bulk BBO fetch per provider is required.');
$assert($result['total']===1&&$result['dataset']==='cross_venue_quote_observation','Quote result contract broken.');
$rows=$result['rows'][0]['venues'];
$assert($rows[0]['quote_status']==='OBSERVED'
    && $rows[0]['quote_observation']['bid']==='181.01'
    && $rows[0]['quote_observation']['ask']==='181.02', 'Binance BBO must be preserved.');
$assert($rows[1]['quote_status']==='OBSERVED'
    && $rows[1]['equivalence']==='NOT_EQUIVALENT','Distinct Bybit token must remain unverified.');
$assert($rows[2]['quote_observation']['quote_currency']==='USD'
    && $rows[2]['quote_observation']['bid_quantity']==='2','Kraken key and USD quote must be preserved.');
$assert($result['rows'][0]['expected_net']===null&&!$result['rows'][0]['executable'],
    'Observed gross BBO must never masquerade as executable arb.');
unset($client->fixtures['capital_markets.quote_preview.bybit']);
$degraded=(new CrossVenueQuoteSampler($client))->observe('org-a',$discovery);
$assert($degraded['source_health']['BYBIT']['status']==='UNAVAILABLE'
    && $degraded['rows'][0]['venues'][1]['quote_status']==='UNAVAILABLE',
    'Provider outage is not a zero price.');
$client->fixtures['capital_markets.quote_preview.binance']='[{"symbol":"AAPLBUSDT","bidPrice":"181.02","bidQty":"4.25","askPrice":"181.02","askQty":"2.15"}]';
$crossed=(new CrossVenueQuoteSampler($client))->observe('org-a',$discovery);
$assert($crossed['rows'][0]['venues'][0]['quote_status']==='NO_VALID_BBO',
    'Locked/crossed quote must fail closed.');
$stale=$discovery;
$stale['as_of_utc']='2026-01-01T00:00:00Z';
try{(new CrossVenueQuoteSampler($client))->observe('org-a',$stale);throw new RuntimeException('Stale discovery was accepted.');}
catch(InvalidArgumentException){}
echo "Cross-venue read-only BBO observation contract passed.\n";
