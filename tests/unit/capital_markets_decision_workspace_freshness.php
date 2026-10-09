<?php
declare(strict_types=1);

use Domains\CapitalMarkets\Application\Service\DecisionWorkspaceReadService;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$service = (new ReflectionClass(DecisionWorkspaceReadService::class))->newInstanceWithoutConstructor();
$reflect = new ReflectionClass($service);
$trust = $reflect->getMethod('decisionStateTrust');
$summary = $reflect->getMethod('dataHealth');
$rows = $reflect->getMethod('qualityRows');
$market = $reflect->getMethod('marketRows');

$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$time = static fn(int $secondsAgo): string => $now->modify(($secondsAgo >= 0 ? '-' : '+').abs($secondsAgo).' seconds')->format(DATE_ATOM);
$base = ['mode'=>'LIVE', 'trust_status'=>'TRUSTED', 'source_timestamp'=>$time(5), 'updated_at'=>$time(3)];
$assert($trust->invoke($service, $base) === 'TRUSTED', 'Recent live observations remain trusted.');
$assert($trust->invoke($service, array_replace($base, ['source_timestamp'=>$time(125)])) === 'STALE', 'Persisted TRUSTED quote cannot remain trusted after 125 seconds.');
$assert($trust->invoke($service, array_replace($base, ['source_timestamp'=>$time(-80)])) === 'DEGRADED', 'Future source clock cannot count as trusted.');
$assert($trust->invoke($service, array_replace($base, ['source_timestamp'=>null,'updated_at'=>null])) === 'UNAVAILABLE', 'Missing source clock must fail closed.');
$assert($trust->invoke($service, array_replace($base, ['mode'=>'REPLAY'])) === 'DEGRADED', 'Replay data must not be advertised as LIVE trust.');
$assert($trust->invoke($service, array_replace($base, ['trust_status'=>'UNTRUSTED'])) === 'UNTRUSTED', 'Read side cannot upgrade ingestion trust.');

$ref = ['mode'=>'LIVE','quality'=>['status'=>'TRUSTED'], 'reference_age_ms'=>120000, 'updated_at'=>$time(2), 'source_timestamp'=>null];
$assert($trust->invoke($service, $ref) === 'STALE', 'Reference quote age must override fresh processing timestamp.');
$assert($trust->invoke($service, array_replace($ref, ['reference_age_ms'=>1000])) === 'TRUSTED', 'Fresh reference age remains trusted.');
$assert($trust->invoke($service, array_replace($ref, ['source_timestamp'=>null, 'updated_at'=>null])) === 'UNAVAILABLE', 'Reference without a clock cannot remain trusted.');

$staleMarket = array_replace($base, [
    'instrument_id'=>'AAPLx', 'venue_id'=>'KRAKEN', 'source_id'=>'feed',
    'best_quote'=>['bid'=>'100','ask'=>'101'], 'market_status'=>'OPEN',
    'source_timestamp'=>$time(125), 'updated_at'=>$time(124),
]);
$dashboard = ['sources'=>[], 'states'=>[$staleMarket], 'reference_states'=>[]];
$health = $summary->invoke($service, $dashboard);
$assert($health['status']==='STALE' && $health['stale_markets']===1 && $health['trusted_markets']===0,
    'A persisted TRUSTED market must downgrade summary health after a quiet feed.');
$assert($summary->invoke($service, ['sources'=>[['enabled'=>true,'health'=>['connection_state'=>'CONNECTED']]],'states'=>[], 'reference_states'=>[]])['status'] === 'UNAVAILABLE',
    'A connected transport without any market observation must never be healthy.');

$quality = $rows->invoke($service, $dashboard);
$assert(count($quality)===1 && $quality[0]['trust']==='STALE', 'Data Quality rows must match global stale health.');
$assert($quality[0]['quote_age_ms'] !== null && $quality[0]['quote_age_ms'] >= 120000,
    'Quote age must advance as wall clock advances, not freeze at ingestion age.');
$assert(in_array('Market: read-time observation freshness is STALE',$quality[0]['reasons'],true),
    'Data Quality must explain why persisted TRUSTED was downgraded.');
$assert($quality[0]['book_age_ms']===null, 'Read-side must not manufacture independent order-book age.');

echo "CM-DECISION-WORKSPACE read-time market freshness regression passed.\n";
