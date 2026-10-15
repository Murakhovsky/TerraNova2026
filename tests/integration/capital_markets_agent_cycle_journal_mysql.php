<?php
declare(strict_types=1);

use Domains\CapitalMarkets\Infrastructure\Agent\MysqlCapitalMarketsAgentCycleJournal;

if(getenv('COS_CM_AGENT_JOURNAL_TEST')!=='1'){
    throw new RuntimeException('Agent cycle MySQL test requires explicit test flag.');
}
require getenv('COS_TEST_AUTOLOAD')?:dirname(__DIR__,2).'/vendor/autoload.php';
$url=getenv('DATABASE_URL');
$parsed=is_string($url)?parse_url($url):false;
if(!is_array($parsed)||($parsed['scheme']??null)!=='mysql'){
    throw new RuntimeException('Agent cycle journal requires MySQL DATABASE_URL.');
}
$dsn='mysql:host='.($parsed['host']??'mysql').';port='.(int)($parsed['port']??3306).
    ';dbname='.ltrim((string)($parsed['path']??''),'/').';charset=utf8mb4';
$newPdo=static function()use($parsed,$dsn):PDO{
    return new PDO($dsn,rawurldecode((string)($parsed['user']??'')),
        rawurldecode((string)($parsed['pass']??'')),[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false,
        ]);
};
$pdo=$newPdo();
$parallelPdo=$newPdo();
$a=new MysqlCapitalMarketsAgentCycleJournal($pdo);
$b=new MysqlCapitalMarketsAgentCycleJournal($parallelPdo);
$org='cm-journal-ci-'.bin2hex(random_bytes(6));
$other=$org.'-other';
$corr='cycle-'.bin2hex(random_bytes(8));
$question='Assess the persisted paper trade only.';
$opp='opportunity-paper-ci';
$check=static function(bool $truth,string $message):void{
    if(!$truth)throw new RuntimeException($message);
};
$reject=static function(callable $operation):bool{
    try{$operation();return false;}catch(DomainException|RuntimeException){return true;}
};
$stage=static fn(string $name):array=>[
    'stage'=>$name,'run_id'=>bin2hex(random_bytes(8)),
    'agent'=>'capital_markets_test','decision'=>'REVIEW','reason'=>'evidence recorded',
    'confidence'=>0.85,'action_count'=>0,
];
$stages=['Observe','Research','Detect','Assess','Allocate','Execute','Measure','Learn'];
try{
    $run=$a->begin($org,$corr,$opp,$question);
    $check($run['kind']==='ACQUIRED'&&count($run['stages'])===0,'First ownership failed.');
    $token=$run['token'];
    $check($b->begin($org,$corr,$opp,$question)['kind']==='IN_PROGRESS',
        'Parallel worker bypassed idempotency owner lock.');
    $check($reject(fn()=>$b->begin($org,$corr,'wrong-opportunity',$question)),
        'Same tenant/correlation accepted a different opportunity.');
    $check($reject(fn()=>$b->begin($org,$corr,$opp,'changed question')),
        'Same tenant/correlation accepted a changed question.');
    $a->checkpoint($org,$corr,$token,$stage('Observe'),0);
    $check($reject(fn()=>$a->checkpoint($org,$corr,$token,$stage('Observe'),0)),
        'Duplicate/out-of-order AgentRun checkpoint accepted.');
    $check($reject(fn()=>$a->checkpoint($org,$corr,'old-token',$stage('Research'),1)),
        'Invalid fencing token allowed checkpoint.');
    $b->begin($other,$corr,$opp,$question);
    $otherSnapshot=$b->snapshot($other,$corr);
    $check($otherSnapshot!==null&&$otherSnapshot['state']==='RUNNING',
        'Cycle journal failed tenant isolation.');

    // Explicit stale worker recovery after controlled 31-minute pause.
    $forceStale=$pdo->prepare(
        "UPDATE tn_capital_market_agent_cycles SET updated_at=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 31 MINUTE)
         WHERE organization_id=:org AND correlation_id=:corr"
    );
    $forceStale->execute(['org'=>$org,'corr'=>$corr]);
    $check($a->begin($org,$corr,$opp,$question)['kind']==='IN_PROGRESS',
        'Stale RUNNING was automatically stolen without explicit operator recovery.');
    $resumed=$b->begin($org,$corr,$opp,$question,true);
    $check($resumed['kind']==='ACQUIRED'&&count($resumed['stages'])===1,
        'Explicit stale worker recovery failed to replay durable checkpoint.');
    $check($reject(fn()=>$a->checkpoint($org,$corr,$token,$stage('Research'),1)),
        'Old process kept writing after recovery owner change.');
    $current=$resumed['token'];
    for($i=1;$i<6;$i++){
        $b->checkpoint($org,$corr,$current,$stage($stages[$i]),$i);
    }
    $waiting=[
        'status'=>'AWAITING_EXECUTION_EVIDENCE','correlation_id'=>$corr,
        'stage_count'=>6,'stages'=>($b->snapshot($org,$corr))['stages'],
        'next_action'=>'PAPER_EXECUTION_PENDING_HUMAN_APPROVAL','live_trading_enabled'=>false,
    ];
    $b->finish($org,$corr,$current,$waiting);
    $cached=$a->begin($org,$corr,$opp,$question);
    $canonicalize=static function(mixed $value)use(&$canonicalize):mixed{
        if(!is_array($value))return $value;
        foreach($value as $key=>$child)$value[$key]=$canonicalize($child);
        if(!array_is_list($value))ksort($value);
        return $value;
    };
    $check($cached['kind']==='CACHED'
        &&$canonicalize($cached['result'])===$canonicalize($waiting),
        'Idempotent WAITING response was not served from durable journal.');
    $again=$a->begin($org,$corr,$opp,$question,true);
    $check($again['kind']==='ACQUIRED'&&count($again['stages'])===6,
        'Human-approved explicit resume did not preserve first six AgentRuns.');
    $check($reject(fn()=>$b->finish($org,$corr,$current,$waiting)),
        'Old token finalized resumed cycle.');
    $latest=$again['token'];
    $a->checkpoint($org,$corr,$latest,$stage('Measure'),6);
    $a->checkpoint($org,$corr,$latest,$stage('Learn'),7);
    $final=[
        'status'=>'REVIEW_REQUIRED','correlation_id'=>$corr,
        'stage_count'=>8,'stages'=>$a->snapshot($org,$corr)['stages'],
        'next_action'=>'HUMAN_REVIEW_RESEARCH_AND_PERFORMANCE','live_trading_enabled'=>false,
    ];
    $a->finish($org,$corr,$latest,$final);
    $check($b->begin($org,$corr,$opp,$question,true)['kind']==='CACHED',
        'Terminal cycle was replayed after final review.');
    $check($a->snapshot($org,$corr)['attempt']===3,
        'Wrong number of explicit recoveries recorded.');
    $check($reject(fn()=>$a->checkpoint($org,$corr,$latest,$stage('Learn'),8)),
        'Terminal cycle allowed a new checkpoint.');

    // Fail/recover with checkpoint retained, but NEVER retry automatically.
    $bad=$a->begin($org,$corr.'-failed',$opp,$question);
    $a->checkpoint($org,$corr.'-failed',$bad['token'],$stage('Observe'),0);
    $a->failed($org,$corr.'-failed',$bad['token']);
    $check($b->begin($org,$corr.'-failed',$opp,$question)['kind']==='RECOVERY_REQUIRED',
        'Failed cycle retried without operator consent.');
    $recovered=$b->begin($org,$corr.'-failed',$opp,$question,true);
    $check($recovered['kind']==='ACQUIRED'&&count($recovered['stages'])===1,
        'Failed cycle explicit recovery lost previously recorded AgentRun.');
    echo "Capital Markets agent cycle MySQL idempotent checkpoint/recovery passed.\n";
}finally{
    foreach([$pdo,$parallelPdo] as $connection){
        if($connection->inTransaction())$connection->rollBack();
    }
    $delete=$pdo->prepare(
        'DELETE FROM tn_capital_market_agent_cycles
         WHERE organization_id IN (:org,:other) AND correlation_id IN (:corr,:failed)'
    );
    $delete->execute(['org'=>$org,'other'=>$other,'corr'=>$corr,'failed'=>$corr.'-failed']);
}
