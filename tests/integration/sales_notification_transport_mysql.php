<?php
declare(strict_types=1);

use Infrastructure\Integration\N8n\IntegrationOutboxProcessor;
use Infrastructure\Platform\Persistence\Pdo\PdoConnection;

require getenv('COS_TEST_AUTOLOAD') ?: dirname(__DIR__,2).'/symfony/vendor/autoload.php';
$db=new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',getenv('DB_HOST')?:'127.0.0.1',(int)(getenv('DB_PORT')?:3306),getenv('DB_DATABASE')?:'cos'),getenv('DB_USERNAME')?:'cos',getenv('DB_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$check=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};
// Session-local shadows of migrated schemas; no live queue or external sender touched.
$migration=file_get_contents(dirname(__DIR__,2).'/app/migrations/20260823_000016_content_n8n.sql');
foreach(['tn_integration_outbox','tn_webhook_deliveries'] as $table){
    if(!preg_match('/CREATE TABLE IF NOT EXISTS '.preg_quote($table,'/').'\s*\([\s\S]*?;\s*/',$migration,$ddl))throw new RuntimeException('Missing migrated fixture schema.');
    $db->exec(str_replace('CREATE TABLE IF NOT EXISTS','CREATE TEMPORARY TABLE',trim($ddl[0])));
}
$insert=$db->prepare('INSERT INTO tn_integration_outbox(integration,event_type,entity_type,entity_id,payload,dedupe_key,status,attempts,available_at,locked_at,lock_token) VALUES ("n8n","notification.email","notification",NULL,?,?,?,?,"2000-01-01 00:00:00",?,?)');
$seed=static function(?string $key,string $status='pending',int $attempts=0,?string $locked=null)use($db,$insert):int{
    $insert->execute([json_encode(['organization_id'=>'fixture','notification_id'=>$key],JSON_THROW_ON_ERROR),$key,$status,$attempts,$locked,$locked===null?null:str_repeat('a',32)]);
    return (int)$db->lastInsertId();
};
$ok=$seed('notification:SQUO-success');
$bad=$seed('notification:SREM-failure');
$stale=$seed('notification:SQUO-expired','processing',1,'2000-01-01 00:00:00');
$legacy=$seed(null);
$calls=[];
$worker=new IntegrationOutboxProcessor(new PdoConnection($db),static function(string $body,array $item)use(&$calls,$ok,$bad):bool{
    $id=(int)$item['id'];$calls[]=$id;
    if($id===$bad)throw new RuntimeException('fixture unknown transport result');
    return $id===$ok;
});
$read=static function(int $id)use($db):array{$q=$db->prepare('SELECT * FROM tn_integration_outbox WHERE id=?');$q->execute([$id]);return $q->fetch();};
$result=$worker->process(20);
$check($result===['claimed'=>3,'sent'=>1,'failed'=>2],'First transport pass mismatch.');
$check($read($ok)['status']==='sent','Successful handoff not stored.');
foreach([$bad,$stale] as $id){$row=$read($id);$check($row['status']==='failed'&&$row['last_error']==='sales_transport_ambiguous'&&(int)$row['attempts']===1,'Ambiguous handoff not quarantined.');}
$check(!in_array($stale,$calls,true),'Expired Sales claim sent again.');
$db->exec('UPDATE tn_integration_outbox SET available_at="2000-01-01 00:00:00" WHERE status="failed"');
$check($worker->process(20)===['claimed'=>1,'sent'=>0,'failed'=>1],'Only legacy failure should retry.');
$check(count(array_filter($calls,static fn(int $id):bool=>$id===$bad))===1,'Ambiguous Sales email retried.');
$check((int)$read($legacy)['attempts']===2,'Legacy retry broken.');
$check((int)$db->query('SELECT COUNT(*) FROM tn_webhook_deliveries')->fetchColumn()===3,'Delivery evidence mismatch.');
echo "Sales transport handoff, ambiguity quarantine, expired lease and legacy retry PASS.\n";
