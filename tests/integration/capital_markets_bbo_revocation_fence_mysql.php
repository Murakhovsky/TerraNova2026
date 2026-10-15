<?php
declare(strict_types=1);

require (getenv('COS_TEST_AUTOLOAD') ?: dirname(__DIR__,2).'/vendor/autoload.php');

use Domains\CapitalMarkets\Application\Service\CrossVenuePoolProjection;
use Domains\CapitalMarkets\Infrastructure\Persistence\MySql\CrossVenuePoolCandidateRepository;
use Domains\CapitalMarkets\Infrastructure\Persistence\MySql\CrossVenueListingReviewRepository;
use Domains\CapitalMarkets\Infrastructure\Persistence\MySql\CrossVenueBboAdmissionFence;

$pdo=new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
    getenv('DB_HOST') ?: '127.0.0.1',(int)(getenv('DB_PORT') ?: 3306),
    getenv('DB_DATABASE') ?: 'cos'),getenv('DB_USERNAME') ?: 'cos',
    getenv('DB_PASSWORD') ?: '',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$assert=static function(bool $good,string $reason):void {if(!$good)throw new RuntimeException($reason);};
$org='cm-fence-ci-'.bin2hex(random_bytes(6));
$fence=new CrossVenueBboAdmissionFence($pdo);
$pool=new CrossVenuePoolCandidateRepository($pdo,new CrossVenuePoolProjection(),$fence);
$review=new CrossVenueListingReviewRepository($pdo,$fence);
$now=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
$snapshot=['dataset'=>'cross_venue_candidate_discovery','as_of_utc'=>$now,
    'rows'=>[['candidate'=>['token'=>'AAPLB','underlying'=>'AAPL'],'venues'=>[
        ['venue'=>'BINANCE','status'=>'LISTED_EXACT_TOKEN_SYMBOL','symbol'=>'AAPLBUSDT','quote'=>'USDT'],
        ['venue'=>'BYBIT','status'=>'LISTED_EXACT_TOKEN_SYMBOL','symbol'=>'AAPLBUSDT','quote'=>'USDT'],
        ['venue'=>'KRAKEN','status'=>'UNAVAILABLE'],
    ]]],
];
$getStates=static function()use($pdo,$org):array {
    $stmt=$pdo->prepare("SELECT v.code,s.status FROM tn_capital_market_subscriptions s
        INNER JOIN tn_capital_market_venues v ON v.organization_id=s.organization_id AND v.venue_id=s.venue_id
        WHERE s.organization_id=:org ORDER BY v.code");
    $stmt->execute(['org'=>$org]);
    return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
};
try {
    $pool->reconcile($org,$snapshot);
    $stamp=$pool->listedForVenue($org,'BINANCE');
    $assert($stamp===[],'Discovery alone cannot approve a subscription.');
    $raw=$pdo->prepare("SELECT source_as_of FROM tn_capital_market_cross_venue_pool_candidates
        WHERE organization_id=:org AND seed_token='AAPLB' AND venue='BINANCE'");
    $raw->execute(['org'=>$org]);
    $stamp=(string)$raw->fetchColumn();
    $review->decide($org,17,'AAPLB','BINANCE',$stamp,'APPROVE_LISTING',
        'https://www.binance.com/en/trade/AAPLB_USDT',hash('sha256','Binance evidence'));
    $review->decide($org,17,'AAPLB','BYBIT',$stamp,'APPROVE_LISTING',
        'https://www.bybit.com/en/trade/spot/AAPLB/USDT',hash('sha256','Bybit evidence'));

    $pdo->prepare("INSERT INTO tn_capital_market_instruments
        (organization_id,instrument_id,symbol,canonical_symbol,name,family,status,metadata_json,created_at,updated_at)
        VALUES (:org,'seed-token','AAPLB','AAPLB','Seed bStock','tokenized_security','ACTIVE','{}',
            UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))")->execute(['org'=>$org]);
    $pdo->prepare("INSERT INTO tn_capital_market_instrument_identifiers
        (organization_id,instrument_id,identifier_type,identifier_source,identifier_value)
        VALUES (:org,'seed-token','PROVIDER_ID','BINANCE_BSTOCKS_SEED','AAPLB')")
        ->execute(['org'=>$org]);

    foreach (['BINANCE','BYBIT'] as $venue) {
        $id='venue:'.$venue;
        $source='source:'.$venue;
        $pdo->prepare("INSERT INTO tn_capital_market_venues
            (organization_id,venue_id,name,code,venue_type,status,timezone,metadata_json,created_at,updated_at)
            VALUES (:org,:id,:name,:code,'tokenized_securities_venue','ACTIVE','UTC','{}',
                UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))")
            ->execute(['org'=>$org,'id'=>$id,'name'=>$venue.' Spot','code'=>$venue]);
        $pdo->prepare("INSERT INTO tn_capital_market_venue_instruments
            (organization_id,venue_id,instrument_id,venue_symbol,status,price_precision,quantity_precision,metadata_json)
            VALUES (:org,:venue,'seed-token','AAPLBUSDT','ACTIVE',4,8,'{}')")
            ->execute(['org'=>$org,'venue'=>$id]);
        $pdo->prepare("INSERT INTO tn_capital_market_data_sources
            (organization_id,source_id,venue_id,adapter_type,enabled,priority,roles_json,
            rate_limit_policy_json,reconnect_policy_json,health_policy_json,license_profile,metadata_json)
            VALUES (:org,:source,:venue,:adapter,1,1,:roles,'{}','{}','{}','PUBLIC_READ_ONLY',:metadata)")
            ->execute(['org'=>$org,'source'=>$source,'venue'=>$id,
                'adapter'=>strtolower($venue).'.spot.rest',
                'roles'=>'["TRADING_SOURCE"]','metadata'=>'{"data_mode":"REST"}']);
        $pdo->prepare("INSERT INTO tn_capital_market_subscriptions
            (organization_id,subscription_id,source_id,venue_id,instrument_id,target_fingerprint,
            data_type,status,subscribed_at)
            VALUES (:org,:subscription,:source,:venue,'seed-token',:fingerprint,'BBO','ACTIVE',UTC_TIMESTAMP(6))")
            ->execute(['org'=>$org,'subscription'=>'subscription:'.$venue,'source'=>$source,
                'venue'=>$id,'fingerprint'=>hash('sha256',$org.'|'.$source)]);
    }
    $assert($getStates()===['BINANCE'=>'ACTIVE','BYBIT'=>'ACTIVE'],'Fixture subscriptions not active.');
    // A fresh public discovery cannot silently renew an old HUMAN review.
    $pdo->prepare("UPDATE tn_capital_market_pool_listing_reviews
        SET reviewed_at=UTC_TIMESTAMP(6)-INTERVAL 49 HOUR
        WHERE organization_id=:org AND venue='BYBIT' AND decision='APPROVE_LISTING'")
        ->execute(['org'=>$org]);
    $assert($pool->listedForVenue($org,'BYBIT')===[],
        'Fresh source observations incorrectly renewed a 49-hour-old human approval.');
    $assert(count($pool->listedForVenue($org,'BINANCE'))===1,
        'Expiry of Bybit approval contaminated independently reviewed Binance.');
    $assert($fence->disableUnapproved($org)===1,
        'Expired human review must immediately disable the active Bybit subscription.');
    $assert($getStates()===['BINANCE'=>'ACTIVE','BYBIT'=>'DISABLED'],
        'Expired reviewer approval disabled the wrong venue.');
    $review->decide($org,17,'AAPLB','BYBIT',$stamp,'APPROVE_LISTING',
        'https://www.bybit.com/en/trade/spot/AAPLB/USDT',hash('sha256','Renewed Bybit evidence'));
    $pdo->prepare("UPDATE tn_capital_market_subscriptions SET status='ACTIVE'
        WHERE organization_id=:org AND subscription_id='subscription:BYBIT'")
        ->execute(['org'=>$org]);
    $assert($getStates()===['BINANCE'=>'ACTIVE','BYBIT'=>'ACTIVE'],
        'Fresh explicit reviewer approval did not re-admit a valid Bybit subscription.');

    $revoked=$review->revoke($org,17,'AAPLB','BINANCE','Operator emergency suspension of invalid listing.');
    $assert($revoked['disabled_subscriptions']===1,
        'Revocation must disable exactly the affected venue BBO.');
    $assert($getStates()===['BINANCE'=>'DISABLED','BYBIT'=>'ACTIVE'],
        'Revocation contaminated healthy other venue or failed to fence Binance.');
    // A newer discovery with identical symbol must not resurrect an operator veto.
    $identical=$snapshot;
    $identical['as_of_utc']=(new DateTimeImmutable('+1 second',new DateTimeZone('UTC')))
        ->format('Y-m-d\TH:i:s\Z');
    $pool->reconcile($org,$identical);
    $assert($pool->listedForVenue($org,'BINANCE')===[]
        && $getStates()===['BINANCE'=>'DISABLED','BYBIT'=>'ACTIVE'],
        'Discovery incorrectly resurrected an explicitly revoked listing.');

    $changed=$snapshot;
    $changed['as_of_utc']=(new DateTimeImmutable('+3 seconds',new DateTimeZone('UTC')))
        ->format('Y-m-d\TH:i:s\Z');
    $changed['rows'][0]['venues'][1]['symbol']='AAPLBUSD';
    $changed['rows'][0]['venues'][1]['quote']='USD';
    $pool->reconcile($org,$changed);
    $assert($getStates()===['BINANCE'=>'DISABLED','BYBIT'=>'DISABLED'],
        'Listing symbol or quote drift left an active stale BBO subscription.');
    $pdo->prepare("UPDATE tn_capital_market_subscriptions SET status='ACTIVE'
        WHERE organization_id=:org AND subscription_id='subscription:BYBIT'")
        ->execute(['org'=>$org]);
    $pdo->prepare("UPDATE tn_capital_market_cross_venue_pool_candidates
        SET source_as_of='2020-01-01T00:00:00.000000Z',verification_state='REVIEWED_LISTING'
        WHERE organization_id=:org AND seed_token='AAPLB' AND venue='BYBIT'")
        ->execute(['org'=>$org]);
    $assert($fence->disableUnapproved($org)===1,'Expiry fence did not disable stale reviewed subscription.');
    $assert($getStates()===['BINANCE'=>'DISABLED','BYBIT'=>'DISABLED'],
        'Stale quote remained subscribed despite freshness gate.');
    echo "MySQL BBO revocation: venue-local disable, discovery drift fence, expiry fence passed.\n";
} finally {
    foreach ([
        'tn_capital_market_subscriptions',
        'tn_capital_market_data_sources',
        'tn_capital_market_venue_instruments',
        'tn_capital_market_instrument_identifiers',
        'tn_capital_market_instruments',
        'tn_capital_market_venues',
        'tn_capital_market_pool_listing_reviews',
        'tn_capital_market_cross_venue_pool_candidates',
    ] as $table) {
        $pdo->prepare('DELETE FROM '.$table.' WHERE organization_id=:org')->execute(['org'=>$org]);
    }
}
