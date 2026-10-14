<?php
declare(strict_types=1);

require (getenv('COS_TEST_AUTOLOAD') ?: dirname(__DIR__,2).'/vendor/autoload.php');

use Domains\CapitalMarkets\Application\Service\CrossVenuePoolProjection;
use Domains\CapitalMarkets\Infrastructure\Persistence\MySql\CrossVenuePoolCandidateRepository;
use Domains\CapitalMarkets\Infrastructure\Persistence\MySql\CrossVenueListingReviewRepository;

$pdo=new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
    getenv('DB_HOST') ?: '127.0.0.1',(int)(getenv('DB_PORT') ?: 3306),
    getenv('DB_DATABASE') ?: 'cos'),getenv('DB_USERNAME') ?: 'cos',
    getenv('DB_PASSWORD') ?: '',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$assert=static function(bool $ok,string $why):void { if (!$ok) throw new RuntimeException($why); };
$org='cm-listing-ci-'.bin2hex(random_bytes(5));
$other=$org.'-foreign';
$pool=new CrossVenuePoolCandidateRepository($pdo,new CrossVenuePoolProjection());
$review=new CrossVenueListingReviewRepository($pdo);
$now=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
$snapshot=['dataset'=>'cross_venue_candidate_discovery','as_of_utc'=>$now,
    'rows'=>[['candidate'=>['token'=>'AAPLB','underlying'=>'AAPL'],
        'venues'=>[
            ['venue'=>'BINANCE','status'=>'LISTED_EXACT_TOKEN_SYMBOL','symbol'=>'AAPLBUSDT','quote'=>'USDT'],
            ['venue'=>'BYBIT','status'=>'UNAVAILABLE'],
            ['venue'=>'KRAKEN','status'=>'NOT_LISTED','symbol'=>null,'quote'=>null],
        ],
    ]],
];
$url='https://www.binance.com/en/trade/AAPLB_USDT';
$hash=hash('sha256','reviewed official listing evidence');
$reviewDecision=static fn(string $scope,string $stamp,string $decision,string $link) =>
    $review->decide($scope,17,'AAPLB','BINANCE',$stamp,$decision,$link,$hash);
$get=static function(string $scope)use($pdo):array {
    $q=$pdo->prepare('SELECT verification_state,listing_state,venue_symbol,quote_currency,
        economic_equivalence,source_as_of FROM tn_capital_market_cross_venue_pool_candidates
        WHERE organization_id=:org AND seed_token=:token AND venue=:venue');
    $q->execute(['org'=>$scope,'token'=>'AAPLB','venue'=>'BINANCE']);
    return $q->fetch(PDO::FETCH_ASSOC) ?: [];
};
$history=static function(string $scope)use($pdo):int {
    $q=$pdo->prepare('SELECT COUNT(*) FROM tn_capital_market_pool_listing_reviews WHERE organization_id=:org');
    $q->execute(['org'=>$scope]);
    return (int)$q->fetchColumn();
};
try {
    $assert($pool->reconcile($org,$snapshot)===2,'Discovery inserted wrong listing states.');
    $source=$get($org)['source_as_of'];
    $assert($pool->listedForVenue($org,'BINANCE')===[],'Unreviewed symbol automatically subscribed.');
    $assert(count($review->pending($org))===1,'Review UI must only show exact live listing rows.');
    foreach ([
        [$org,'2000-01-01T00:00:00.000000Z','APPROVE_LISTING',$url],
        [$org,$source,'APPROVE_LISTING','https://evil.example.com/steal'],
        [$org,$source,'APPROVE_LISTING','http://www.binance.com/en/trade/AAPLB_USDT'],
        [$other,$source,'APPROVE_LISTING',$url],
    ] as $args) {
        try {
            $reviewDecision(...$args);
            throw new RuntimeException('Unsafe review request was accepted.');
        } catch (InvalidArgumentException|RuntimeException $e) {
            $assert(!str_contains($e->getMessage(),'was accepted'),'Unexpected successful review.');
        }
    }
    $approved=$reviewDecision($org,$source,'APPROVE_LISTING',$url);
    $assert($approved['status']==='REVIEWED_LISTING','Listing decision not written.');
    $assert($get($org)['economic_equivalence']==='NOT_ESTABLISHED',
        'Listing review improperly approved issuer economic equivalence.');
    $listed=$pool->listedForVenue($org,'BINANCE');
    $assert(count($listed)===1 && $listed[0]['venue_symbol']==='AAPLBUSDT',
        'Human-reviewed listing did not enter BBO planning pool.');
    $assert($pool->listedForVenue($other,'BINANCE')===[],'Review leaked between tenants.');
    $assert($history($org)===1 && $history($other)===0,'Review history is not tenant-scoped.');
    $changed=$snapshot;
    $changed['as_of_utc']=(new DateTimeImmutable('+3 seconds',new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    $changed['rows'][0]['venues'][0]['quote']='USD';
    $pool->reconcile($org,$changed);
    $assert($get($org)['verification_state']==='UNVERIFIED'
        && $pool->listedForVenue($org,'BINANCE')===[],
        'Quote drift did not revoke admission.');
    try {
        $reviewDecision($org,$source,'APPROVE_LISTING',$url);
        throw new RuntimeException('Outdated review accepted.');
    } catch (RuntimeException $e) {
        $assert(str_contains($e->getMessage(),'changed'),'Old evidence was unexpectedly accepted.');
    }
    $newSource=$get($org)['source_as_of'];
    $reviewDecision($org,$newSource,'APPROVE_LISTING',$url);
    $assert(count($pool->listedForVenue($org,'BINANCE'))===1,'New evidence cannot be reviewed.');
    $review->revoke($org,17,'AAPLB','BINANCE','Operator found inconsistent official listing evidence.');
    $assert($pool->listedForVenue($org,'BINANCE')===[], 'Operator revocation did not gate subscriptions.');
    $assert($history($org)===3,'Immutable review decisions missing.');
    $reviewDecision($org,$newSource,'APPROVE_LISTING',$url);
    $pdo->prepare("UPDATE tn_capital_market_cross_venue_pool_candidates
        SET source_as_of='2020-01-01T00:00:00.000000Z'
        WHERE organization_id=:org AND seed_token='AAPLB' AND venue='BINANCE'")
        ->execute(['org'=>$org]);
    $assert($pool->listedForVenue($org,'BINANCE')===[],
        'Historical reviewer decision remained eligible after freshness expiry.');
    $urgent=$review->revoke($org,17,'AAPLB','BINANCE',
        'Reference source is stale, revoke monitoring authorization immediately.');
    $assert($urgent['status']==='UNVERIFIED' && $get($org)['verification_state']==='UNVERIFIED'
        && $history($org)===5,
        'Urgent revocation must succeed without source freshness, evidence URL or active provider.');

    echo "MySQL listing review: RBAC boundary contract, evidence, CAS, quote drift, expiry, revoke and tenant isolation passed.\n";
} finally {
    foreach (['tn_capital_market_pool_listing_reviews','tn_capital_market_cross_venue_pool_candidates'] as $table) {
        $pdo->prepare('DELETE FROM '.$table.' WHERE organization_id IN (:org,:other)')
            ->execute(['org'=>$org,'other'=>$other]);
    }
}
