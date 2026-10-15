<?php
declare(strict_types=1);

require (getenv('COS_TEST_AUTOLOAD') ?: dirname(__DIR__,2).'/vendor/autoload.php');

use Domains\CapitalMarkets\Infrastructure\Persistence\MySql\MysqlBoundedBboSweepGate;

$dsn=sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
    getenv('DB_HOST') ?: '127.0.0.1',(int)(getenv('DB_PORT') ?: 3306),
    getenv('DB_DATABASE') ?: 'cos');
$user=getenv('DB_USERNAME') ?: 'cos';
$pass=getenv('DB_PASSWORD') ?: '';
$pdo=new PDO($dsn,$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$other=new PDO($dsn,$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$org='cm-bbo-ci-'.bin2hex(random_bytes(6));
$gate=new MysqlBoundedBboSweepGate($pdo);
$secondGate=new MysqlBoundedBboSweepGate($other);
$assert=static function(bool $good,string $reason):void {
    if (!$good) throw new RuntimeException($reason);
};
$hits=0;
try {
    $once=$gate->run($org,'test-source',300,static function()use(&$hits):string {++$hits;return 'OK';});
    $assert($once===['state'=>'POLLED','status'=>'OK'] && $hits===1,'Initial BBO gate failed.');
    $next=$gate->run($org,'test-source',300,static function()use(&$hits):string {++$hits;return 'OK';});
    $assert($next['state']==='COOLDOWN' && $hits===1,'Cooldown allowed duplicate provider calls.');
    $isolated=$secondGate->run($org.'-other','test-source',300,static fn():string=>'OK');
    $assert($isolated['state']==='POLLED','Foreign tenant must have independent cooldown.');
    $pdo->prepare("UPDATE tn_capital_market_bbo_poll_gates
        SET last_attempt_at=UTC_TIMESTAMP(6)-INTERVAL 500 SECOND
        WHERE organization_id=:org AND source_id='test-source'")->execute(['org'=>$org]);
    $failed=$gate->run($org,'test-source',300,static fn():string=>'FAILED');
    $assert($failed===['state'=>'POLLED','status'=>'FAILED'],'Failed provider observation not persisted.');
    $assert($gate->run($org,'test-source',300,static fn():string=>'OK')['state']==='COOLDOWN',
        'Failed attempts must consume cooldown to protect rate limits.');
    $mutex='cm-bbo-'.substr(hash('sha256',$org.'|test-source'),0,50);
    $lock=$pdo->prepare('SELECT GET_LOCK(:name,0)');
    $lock->execute(['name'=>$mutex]);
    $assert((int)$lock->fetchColumn()===1,'Could not establish competing worker lease.');
    try {
        $busy=$secondGate->run($org,'test-source',300,
            static function():string {throw new RuntimeException('Lease not fenced.');});
        $assert($busy['state']==='LEASE_BUSY','Second worker bypassed exclusive source lock.');
    } finally {
        $pdo->prepare('SELECT RELEASE_LOCK(:name)')->execute(['name'=>$mutex]);
    }
    $state=$pdo->prepare('SELECT last_status FROM tn_capital_market_bbo_poll_gates
        WHERE organization_id=:org AND source_id=:source');
    $state->execute(['org'=>$org,'source'=>'test-source']);
    $assert($state->fetchColumn()==='FAILED','Source gate status differs from provider failure.');
    $recent=$gate->recent($org,10,1800);
    $assert(count($recent)===1 && $recent[0]['freshness']==='RECENT',
        'Operator read model should expose recent completed observation.');
    $pdo->prepare("UPDATE tn_capital_market_bbo_poll_gates
       SET last_completed_at=UTC_TIMESTAMP(6)-INTERVAL 1900 SECOND
       WHERE organization_id=:org AND source_id='test-source'")
       ->execute(['org'=>$org]);
    $aged=$gate->recent($org,10,1800);
    $assert($aged[0]['freshness']==='STALE','Old BBO poll must be marked STALE.');
    $assert($gate->recent($org.'-unknown')===[],'Foreign tenant can read BBO poll evidence.');

    echo "Bounded BBO MySQL: lease, cooldown, failure fencing, tenant isolation passed.\n";
} finally {
    $pdo->prepare('DELETE FROM tn_capital_market_bbo_poll_gates WHERE organization_id IN (:org,:other)')
        ->execute(['org'=>$org,'other'=>$org.'-other']);
}
