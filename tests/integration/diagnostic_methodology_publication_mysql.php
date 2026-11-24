<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
require (getenv('COS_TEST_AUTOLOAD')?:$root.'/vendor/autoload.php');

use Domains\Diagnostic\Application\Service\MethodologyStudioService;
use Domains\Diagnostic\Infrastructure\Persistence\MySql\MysqlMethodologyStudioRepository;
use Domains\Diagnostic\Infrastructure\Persistence\MySql\MysqlDiagnosticPackRepository;
use Domains\Diagnostic\Methodology\Serialization\MethodologyPackSerializer;

$connect=static fn()=>new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    getenv('DB_HOST')?:'127.0.0.1',getenv('DB_PORT')?:'3306',getenv('DB_DATABASE')?:'cos'),
    getenv('DB_USERNAME')?:'cos',getenv('DB_PASSWORD')?:'',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$db=$connect();$other=$connect();
$repo=new MysqlMethodologyStudioRepository($db);
$native=new MysqlDiagnosticPackRepository($db);
$studio=new MethodologyStudioService($repo);
$org='default';$pack='das-studio-'.bin2hex(random_bytes(6));$v='0.2.0';
$check=static function(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);};
$reject=static function(callable $op):void{try{$op();}catch(DomainException){return;}throw new RuntimeException('Unsafe mutation was accepted.');};

try{
    $studio->create($org,['slug'=>$pack,'name'=>'DAS Sales Diagnostic','domain'=>'Sales','methodology_version'=>$v],'31');
    $source=json_decode(file_get_contents($root.'/resources/diagnostic/sales/0.1.0/sales-diagnostic-pack.json'),true,512,JSON_THROW_ON_ERROR);
    $map=['sections'=>'AREA','criteria'=>'CRITERION','facts'=>'FACT','metrics'=>'METRIC','questions'=>'QUESTION','evidence_requirements'=>'EVIDENCE_REQUIREMENT','rules'=>'RULE','dependencies'=>'DEPENDENCY','recommendations'=>'RECOMMENDATION','benchmarks'=>'BENCHMARK','scoring'=>'SCORING'];
    foreach($map as $key=>$type)foreach($source[$key]??[] as $i=>$entity)$studio->saveEntity($org,$pack,$v,$type,$entity+['order'=>$i],'31');
    $before=$repo->version($org,$pack,$v);
    $reject(fn()=> $studio->publish($org,$pack,$v,'31'));
    $check($repo->version($org,$pack,$v)===$before && $native->get($org,$pack,2000)===null,'Failed publish leaked native version or Studio status.');
    $metrics=[];foreach($source['metrics'] as $m)$metrics[$m['id']]=$m['direction']==='lower_is_better'?10:90;
    $facts=array_fill_keys(array_column($source['facts'],'id'),true);
    $studio->saveScenario($org,$pack,$v,['id'=>'healthy','name'=>'Healthy','input'=>['facts'=>$facts,'metrics'=>$metrics],'expected'=>['findings'=>[]]],'31');
    $serializer=new MethodologyPackSerializer();
    $hash=$serializer->hash($studio->compile($org,$pack,$v)->pack);
    $studio->publish($org,$pack,$v,'31');
    $published=$repo->version($org,$pack,$v);
    $p=$native->get($org,$pack,2000);
    $check($published['status']==='PUBLISHED' && $published['content_hash']===$hash && $p!==null && $p->contentHash()===$hash && $p->status()->value==='published','Compiled native revision differs from Studio fingerprint.');
    $check((int)$repo->pack($org,$pack)['active_version']===2000 && $repo->pack($org,$pack)['active_methodology_version']===$v,'Activation did not commit.');
    $audit=$repo->auditHistory($org,$pack,$v);
    $studio->publish($org,$pack,$v,'31');
    $check($repo->version($org,$pack,$v)===$published && $repo->auditHistory($org,$pack,$v)===$audit,'Replay wrote new publication/audit.');
    $reject(fn()=> $repo->withMutation($org,fn()=> $repo->publishCompiled($org,['pack_id'=>$pack,'name'=>'forged','domain'=>'Sales','methodology_version'=>$v],2000,'{}',str_repeat('f',64))));
    $check($native->get($org,$pack,2000)?->contentHash()===$hash,'Native overwrite was not fenced.');
    $reject(fn()=> $studio->saveEntity($org,$pack,$v,'FACT',$source['facts'][0],'31'));
    $reject(fn()=> $studio->archive($org,$pack,$v,'31'));
    $reject(fn()=> $studio->publish('missing-das-tenant',$pack,$v,'31'));

    // Hold tenant mutation lock on connection A; connection B must not enter the critical section.
    $db->beginTransaction();
    try{
        $repo->withMutation($org,static fn()=>null);
        $other->exec('SET SESSION innodb_lock_wait_timeout=1');
        $entered=false;$blocked=false;
        try{(new MysqlMethodologyStudioRepository($other))->withMutation($org,function()use(&$entered){$entered=true;});}
        catch(PDOException $e){$blocked=(int)($e->errorInfo[1]??0)===1205;}
        $check($blocked && !$entered && !$other->inTransaction(),'Parallel tenant mutation bypassed lock or leaked transaction.');
    }finally{$db->rollBack();}
    $check((new MysqlMethodologyStudioRepository($other))->withMutation($org,static fn()=>true)===true,'Lock did not release after rollback.');
}finally{
    if($db->inTransaction())$db->rollBack();
    foreach(['diagnostic_test_results','diagnostic_methodology_audit','diagnostic_test_scenarios','diagnostic_methodology_entities','diagnostic_pack_versions','diagnostic_packs'] as $table){
        $q=$db->prepare('DELETE FROM '.$table.' WHERE organization_id=? AND pack_id=?');$q->execute([$org,$pack]);
    }
}
echo "Diagnostic Studio MySQL atomic publication, native immutability and two-connection locking PASS.\n";
