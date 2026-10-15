<?php
declare(strict_types=1);

/**
 * VPS-only non-trading acceptance records. Always restricted to a dedicated
 * tenant namespace. No cleanup of production organizations or existing sources.
 * DATABASE_URL is inherited from the isolated Symfony CLI container.
 */
$mode = $argv[1] ?? '';
$organization = getenv('FOS_L2_ACCEPTANCE_ORG') ?: '';
if (!preg_match('/^l2-vps-accept-[0-9]{8}-[a-f0-9]{8}$/D', $organization)) {
    throw new RuntimeException('L2 VPS test organization id must be a fresh isolated namespace.');
}
if (!in_array($mode, ['bootstrap', 'verify'], true)) {
    throw new RuntimeException('Expected bootstrap or verify.');
}
$url = getenv('DATABASE_URL') ?: '';
$parsed = parse_url($url);
if (!is_array($parsed) || ($parsed['scheme'] ?? '') !== 'mysql'
    || empty($parsed['host']) || empty($parsed['path'])) {
    throw new RuntimeException('Symfony DATABASE_URL is not a valid MySQL DSN.');
}
$db = new PDO(
    'mysql:host='.$parsed['host'].';port='.($parsed['port']??3306).
    ';dbname='.rawurldecode(ltrim($parsed['path'],'/')).';charset=utf8mb4',
    rawurldecode($parsed['user']??''),
    rawurldecode($parsed['pass']??''),
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC],
);
$source = 'source:l2-vps-bybit-spot';
$venue = 'venue:l2-vps-bybit-spot';
$instrument = 'instrument:l2-vps-btcusdt';

if ($mode === 'bootstrap') {
    $db->beginTransaction();
    try {
        $q = $db->prepare('SELECT COUNT(*) FROM tn_capital_market_data_sources WHERE organization_id=?');
        $q->execute([$organization]);
        if ((int)$q->fetchColumn() !== 0) throw new RuntimeException('Acceptance organization already initialized.');
        $statements = [
            ['INSERT INTO tn_capital_market_venues
                (organization_id,venue_id,name,code,venue_type,status,jurisdiction,timezone,base_url_reference,metadata_json,created_at,updated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,NOW(6),NOW(6))',
                [$organization,$venue,'Bybit Spot VPS Acceptance','BYBIT_SPOT','cex','ACTIVE',null,'UTC',null,'{"vps_acceptance":true}']],
            ['INSERT INTO tn_capital_market_instruments
                (organization_id,instrument_id,symbol,canonical_symbol,name,family,status,currency,quote_asset,
                 issuer_reference,jurisdiction,primary_venue_reference,metadata_json,created_at,updated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(6),NOW(6))',
                [$organization,$instrument,'BTCUSDT','BTCUSDT','BTC/USDT VPS L2 acceptance','spot_market','ACTIVE',
                 null,'USDT',null,null,$venue,'{"vps_acceptance":true}']],
            ['INSERT INTO tn_capital_market_venue_instruments
                (organization_id,venue_id,instrument_id,venue_symbol,status,price_precision,quantity_precision,
                 minimum_quantity,minimum_notional,metadata_json,created_at,updated_at)
                VALUES (?,?,?,?,?,30,30,NULL,NULL,?,NOW(6),NOW(6))',
                [$organization,$venue,$instrument,'BTCUSDT','ACTIVE','{"vps_acceptance":true}']],
            ['INSERT INTO tn_capital_market_data_sources
                (organization_id,source_id,venue_id,adapter_type,enabled,priority,roles_json,credentials_reference,
                 rate_limit_policy_json,reconnect_policy_json,health_policy_json,quality_policy_json,
                 license_profile,metadata_json)
                VALUES (?,?,?,?,1,100,?,NULL,?,?,?,NULL,?,?)',
                [$organization,$source,$venue,'l2.verified','["TRADING_SOURCE"]',
                '{"request_budget":500,"window_seconds":60,"initial_backoff_ms":250,"maximum_backoff_ms":10000}',
                '{"initial_delay_ms":1000,"maximum_delay_ms":30000,"maximum_attempts":0}',
                '{"heartbeat_interval_ms":15000,"heartbeat_timeout_ms":45000,"maximum_queue_lag":10000,"maximum_clock_drift_ms":1000}',
                'PUBLIC_MARKET_DATA','{"vps_acceptance":true,"venue":"BYBIT_SPOT","symbol":"BTCUSDT"}']],
            ['INSERT INTO tn_capital_market_source_health
                (organization_id,source_id,connection_state,last_event_at,last_heartbeat_at,
                 failure_count,queue_lag,clock_reliable,rate_limit_state)
                VALUES (?,?,"ACTIVE",NOW(6),NOW(6),0,0,1,"UNKNOWN")',
                [$organization,$source]],
        ];
        foreach ($statements as [$sql,$values]) {
            $stmt = $db->prepare($sql);
            $stmt->execute($values);
        }
        $db->commit();
        echo json_encode(['result'=>'BOOTSTRAPPED','organization'=>$organization,'source'=>$source,
            'venue'=>$venue,'instrument'=>$instrument],JSON_THROW_ON_ERROR)."\n";
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
    exit(0);
}

$counts=[];
foreach ([
    'raw'=>'tn_capital_market_raw_events',
    'canonical'=>'tn_capital_market_canonical_events',
    'quality'=>'tn_capital_market_quality_metrics',
    'state'=>'tn_capital_market_states',
] as $label=>$table) {
    $stmt = $db->prepare('SELECT COUNT(*) FROM '.$table.' WHERE organization_id=?');
    $stmt->execute([$organization]);
    $counts[$label] = (int)$stmt->fetchColumn();
}
$rawQ=$db->prepare('SELECT sequence_value,raw_payload_json FROM tn_capital_market_raw_events WHERE organization_id=? ORDER BY id DESC LIMIT 1');
$rawQ->execute([$organization]);$raw=$rawQ->fetch();
$canonicalQ=$db->prepare('SELECT sequence_value,event_type,data_mode,payload_json,source_id,venue_id,instrument_id
 FROM tn_capital_market_canonical_events WHERE organization_id=? ORDER BY id DESC LIMIT 1');
$canonicalQ->execute([$organization]);$canonical=$canonicalQ->fetch();
$stateQ=$db->prepare('SELECT last_sequence,trust_status,state_version,quality_score,data_mode,source_id,state_json
 FROM tn_capital_market_states WHERE organization_id=? AND venue_id=? AND instrument_id=?');
$stateQ->execute([$organization,$venue,$instrument]);$state=$stateQ->fetch();
if (min($counts) < 1 || !is_array($raw) || !is_array($canonical) || !is_array($state)
    || $canonical['event_type'] !== 'ORDER_BOOK_SNAPSHOT' || $canonical['data_mode'] !== 'LIVE'
    || $canonical['source_id'] !== $source || $canonical['venue_id'] !== $venue
    || $canonical['instrument_id'] !== $instrument
    || $raw['sequence_value'] !== $canonical['sequence_value']
    || $state['last_sequence'] !== $canonical['sequence_value']
    || empty(json_decode($raw['raw_payload_json'],true)['book']['bids'])
    || empty(json_decode($canonical['payload_json'],true)['bids'])) {
    throw new RuntimeException('Verified exchange book is not persisted consistently across raw/canonical/quality/state.');
}
echo json_encode([
    'result'=>'PASS','organization'=>$organization,'source'=>$source,
    'database'=>$counts,'sequence'=>$canonical['sequence_value'],
    'state_version'=>(int)$state['state_version'],
    'state_trust'=>$state['trust_status'],'state_quality'=>(int)$state['quality_score'],
    'live_market_data'=>true,
],JSON_THROW_ON_ERROR)."\n";
