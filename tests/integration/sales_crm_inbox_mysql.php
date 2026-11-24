<?php
declare(strict_types=1);

use App\Application\Capability\CanonicalCapabilityGateway;
use App\Application\Integration\Command\RecoverCrmInboxCommand;
use App\Application\Integration\Command\RecoverCrmInboxCommandHandler;
use App\Application\Integration\IntegrationMutationAudit;
use App\Infrastructure\Capability\SalesCrmInboxStatusAdapter;
use App\Infrastructure\Capability\SalesCrmInboxRecoverAdapter;
use App\Infrastructure\Messenger\SymfonyCommandBus;
use App\Persistence\Federation\FederationCapabilityBindingResolver;
use Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesMutationReceiptRepository;
use Infrastructure\Platform\Persistence\MySql\Audit\MysqlAuditRepository;
use Kernel\Identity\Model\OrganizationRole;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Module\CanonicalCapabilityCatalog;
use Kernel\Module\Contract\ModuleStateRepositoryInterface;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleDefinition;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Shared\Domain\UserId;
use Kernel\Tenant\Model\Permission;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Domains\Sales\Application\Contract\CrmInboundApplierInterface;
use Domains\Sales\Application\DTO\CrmInboxItem;
use Domains\Sales\Application\UseCase\ProcessCrmInbox;
use Domains\Sales\Automation\Event\LeadChanged;
use Domains\Sales\Automation\Event\ClientCaseChanged;
use Domains\Property\Infrastructure\ReadModel\MySql\MysqlPropertyReferencePort;
use Domains\Sales\Infrastructure\Property\SalesPropertyReference;
use Domains\Sales\Infrastructure\Persistence\MySql\MysqlClientCaseCommandRepository;
use Domains\Sales\Infrastructure\Persistence\MySql\MysqlInboundLeadRepository;
use Infrastructure\Integration\Crm\MysqlCrmInboxRepository;
use Infrastructure\Integration\Crm\MysqlCrmInboundApplier;
use Infrastructure\Platform\Persistence\MySql\Event\MysqlEventStore;
use Infrastructure\Platform\Persistence\MySql\Transaction\TransactionManager;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Module\Contract\EventOwningModuleInterface;
use Kernel\Module\DomainModuleInterface;
use Kernel\Module\DomainModuleRegistry;
use Kernel\Rule\Contract\RuleContextProviderInterface;

require getenv('COS_TEST_AUTOLOAD') ?: dirname(__DIR__,2).'/symfony/vendor/autoload.php';
$check=static function(bool $ok,string $why):void { if(!$ok) throw new RuntimeException($why); };
$reject=static function(callable $fn):void { try {$fn();} catch(DomainException){return;} throw new RuntimeException('Expected refusal.'); };
$connect=static fn()=>new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',getenv('DB_HOST')?:'127.0.0.1',(int)(getenv('DB_PORT')?:3306),getenv('DB_DATABASE')?:'cos'),
    getenv('DB_USERNAME')?:'cos',getenv('DB_PASSWORD')?:'',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC],
);
$a=$connect();$b=$connect();
$org=(string)$a->query("SELECT id FROM cos_organizations WHERE status='ACTIVE' ORDER BY id LIMIT 1")->fetchColumn();
$check($org!=='','Missing migrated organization.');
$token=bin2hex(random_bytes(8));$correlation='crm-ci-'.$token;
$repo=new MysqlCrmInboxRepository($a);$other=new MysqlCrmInboxRepository($b);
$tx=new TransactionManager($a);
$ids=[];$lead=null;$case=null;$person=null;$managerId=null;
$read=static function(string $id)use($b,$org):array{
    $q=$b->prepare('SELECT * FROM cos_crm_inbox WHERE organization_id=? AND id=?');$q->execute([$org,$id]);return $q->fetch()?:[];
};
$admit=static function(string $suffix,array $payload,string $eventType='lead.updated')use($tx,$repo,$org,$token,$correlation,&$ids):string{
    $id=$tx->transactional(fn()=>$repo->receive($org,'aida',$token.'-'.$suffix,$eventType,$payload,$correlation));
    $ids[$id]=$id;return $id;
};
$expire=static function(string $id)use($b,$org):void{
    $b->prepare('UPDATE cos_crm_inbox SET locked_at=DATE_SUB(NOW(6),INTERVAL 301 SECOND) WHERE id=? AND organization_id=?')->execute([$id,$org]);
};
$due=static function(string $id)use($b,$org):void{
    $b->prepare('UPDATE cos_crm_inbox SET available_at=DATE_SUB(NOW(6),INTERVAL 1 SECOND) WHERE id=? AND organization_id=?')->execute([$id,$org]);
};
try{
    $lead=(new MysqlInboundLeadRepository($a))->create($org,[
        'person_id'=>null,'client_case_id'=>null,'property_id'=>null,'full_name'=>'CRM CI '.$token,
        'phone'=>null,'email'=>$token.'@crm.test','role'=>'buyer','deal_type'=>'consultation','message'=>null,'source_page'=>'/ci',
    ]);
    $payload=['entity_type'=>'lead','external_id'=>(string)$lead,'changes'=>[]];
    $id=$admit('lease',$payload);
    $check($admit('lease',$payload)===$id,'Duplicate admission created another item.');
    $reject(fn()=>$admit('lease',[...$payload,'changes'=>['status'=>'won']]));
    $check($other->claim('foreign-'.$token,$id,'worker')===null,'Cross-tenant claim succeeded.');
    $first=$repo->claim($org,$id,'worker');
    $check($first!==null && $first->attempts===1 && $first->claimToken!=='','Initial claim missing.');
    $check($other->claim($org,$id,'other')===null,'Live lease stolen.');
    $expire($id);
    $check(in_array($id,array_column($other->pending(500),'id'),true),'Expired lease not visible to sweep.');
    $second=$other->claim($org,$id,'worker');
    $check($second!==null && $second->attempts===2 && $second->claimToken!==$first->claimToken,'Expired claim not recovered with a unique token.');
    $check(!$tx->transactional(fn()=>$repo->lockClaim($first)),'Stale worker obtained write authority.');
    $reject(fn()=>$tx->transactional(fn()=>$repo->complete($first)));
    $repo->fail($first,new RuntimeException('private stale payload'));
    $check($read($id)['locked_by']===$second->claimToken,'Stale failure overwrote current claim.');
    $b->beginTransaction();
    $check($other->lockClaim($second),'Current worker cannot lock claim.');
    $a->exec('SET SESSION innodb_lock_wait_timeout=1');
    $a->beginTransaction();
    try{
        $a->prepare('SELECT id FROM cos_crm_inbox WHERE id=? FOR UPDATE NOWAIT')->execute([$id]);
        throw new RuntimeException('Processing did not hold the inbox row lock.');
    }catch(PDOException $e){
        $check(in_array((int)($e->errorInfo[1]??0),[1205,3572],true),'Unexpected lock error: '.$e->getMessage());
    }finally{$a->rollBack();$b->rollBack();}
    $other->fail($second,new RuntimeException('private CRM payload'));
    $row=$read($id);
    $check($row['status']==='FAILED' && !str_contains($row['last_error'],'private'),'Failure or PII redaction invalid.');
    $check($repo->claim($org,$id,'early')===null,'Retry backoff not enforced.');
    $due($id);

    $ruleContext=new class implements RuleContextProviderInterface {
        public function contextFor(DomainEvent $event):array{return [];}
    };
    $module=new class($ruleContext) implements DomainModuleInterface,EventOwningModuleInterface {
        public function __construct(private RuleContextProviderInterface $rules){}
        public function name():string{return 'sales';}
        public function eventTypes():array{return [LeadChanged::TYPE,ClientCaseChanged::TYPE];}
        public function ruleContextProvider():RuleContextProviderInterface{return $this->rules;}
    };
    $domains=new DomainModuleRegistry([$module]);
    $events=new EventBus(new MysqlEventStore($a),$tx);
    $processor=new ProcessCrmInbox($repo,new MysqlCrmInboundApplier($a),$domains,$events,$tx);
    $check($processor->execute($org,$id,'processor'),'Recovered event did not process.');
    $check($read($id)['status']==='COMPLETED' && (int)$read($id)['attempts']===3,'Completion state invalid.');
    $check(!$processor->execute($org,$id,'repeat'),'Completed event was applied again.');
    $q=$b->prepare('SELECT COUNT(*) FROM cos_events e INNER JOIN cos_event_outbox o ON o.event_id=e.id WHERE e.organization_id=? AND e.aggregate_id=? AND e.type=?');
    $q->execute([$org,(string)$lead,LeadChanged::TYPE]);
    $check((int)$q->fetchColumn()===0,'Unchanged lead emitted a false business event.');
    $commands=new MysqlClientCaseCommandRepository($a,new SalesPropertyReference(new MysqlPropertyReferencePort($a),$org));
    $tx->transactional(function()use($commands,$org,$token,&$person,&$case):void{
        $person=$commands->createPerson($org,['full_name'=>'CRM CI '.$token,'phone'=>null,'email'=>$token.'@crm-person.test','telegram'=>null,'notes'=>null]);
        $case=$commands->createCase($org,$person,['type'=>'buy','title'=>'CRM CI '.$token,'status'=>'active','stage'=>'new','priority'=>'normal','assigned_user_id'=>null,'source'=>'integration','property_type_id'=>null,'location_id'=>null,'budget_min'=>null,'budget_max'=>null,'currency'=>'USD','area_min'=>null,'area_max'=>null,'description'=>null,'parameters_json'=>null,'next_contact_at'=>null,'closed_at'=>null]);
    });
    $deal=$admit('deal',['entity_type'=>'deal','external_id'=>(string)$case,'changes'=>['priority'=>'high']],'deal.updated');
    $check($processor->execute($org,$deal,'processor'),'Native deal update failed.');
    $check(!$processor->execute($org,$deal,'repeat'),'Completed deal was reprocessed.');
    $q=$b->prepare('SELECT priority FROM tn_client_cases WHERE organization_id=? AND id=?');$q->execute([$org,$case]);
    $check($q->fetchColumn()==='high','Native deal priority was not applied.');
    $q=$b->prepare('SELECT COUNT(*) FROM cos_events e INNER JOIN cos_event_outbox o ON o.event_id=e.id WHERE e.organization_id=? AND e.aggregate_id=? AND e.type=?');$q->execute([$org,(string)$case,ClientCaseChanged::TYPE]);
    $check((int)$q->fetchColumn()===1,'Expected exactly one native changed event/outbox.');
    $invalid=$admit('invalid',[...$payload,'changes'=>['status'=>'won']]);
    $failed=false;
    try{$processor->execute($org,$invalid,'processor');}catch(RuntimeException $e){$failed=str_contains($e->getMessage(),'governed Sales');}
    $check($failed && $read($invalid)['status']==='FAILED','Direct CRM lifecycle mutation was not rejected.');
    $q=$b->prepare('SELECT status FROM tn_leads WHERE organization_id=? AND id=?');$q->execute([$org,$lead]);
    $check($q->fetchColumn()==='new','Rejected mutation changed the native lead.');
    $dead=$admit('dead',$payload);
    $repo->claim($org,$dead,'abandoned');
    $b->prepare('UPDATE cos_crm_inbox SET attempts=10 WHERE id=?')->execute([$dead]);$expire($dead);
    $check($other->claim($org,$dead,'recover')===null && $read($dead)['status']==='DEAD','Exhausted lease not quarantined.');
    $a->prepare('INSERT INTO tn_users (organization_id,email,password_hash,full_name,role,status) VALUES (?,?,?,?,?,?)')
        ->execute([$org,'crm-recover-'.$token.'@example.test',password_hash($token,PASSWORD_DEFAULT),'CRM CI manager','manager','active']);
    $managerId=(int)$a->lastInsertId();
    $a->prepare('INSERT INTO cos_organization_memberships (organization_id,user_id,role,status) VALUES (?,?,?,?)')->execute([$org,$managerId,'manager','ACTIVE']);
    $handler=new RecoverCrmInboxCommandHandler($repo,new MysqlSalesMutationReceiptRepository($a),$tx,new IntegrationMutationAudit(new MysqlAuditRepository($a)));
    $bus=new SymfonyCommandBus(new MessageBus([new HandleMessageMiddleware(new HandlersLocator([RecoverCrmInboxCommand::class=>[$handler]]))]));
    $moduleCatalog=new ModuleCatalog([ModuleDefinition::fromArray(require dirname(__DIR__,2).'/app/Domains/Sales/module.php')]);
    $catalog=new CanonicalCapabilityCatalog($moduleCatalog);
    $state=new class implements ModuleStateRepositoryInterface {
        public function enabledOverride(string $organizationId,string $moduleId):?bool{return true;}
        public function setEnabled(string $organizationId,string $moduleId,bool $enabled):void{}
    };
    $active=new ActiveModuleResolver($moduleCatalog,$state);
    $gateway=new CanonicalCapabilityGateway($catalog,$active,new FederationCapabilityBindingResolver($catalog,new DomainModuleRegistry([]),$active),
        [new SalesCrmInboxStatusAdapter($repo),new SalesCrmInboxRecoverAdapter($bus)]);
    $actor=static fn(string $tenant,string $role='manager')=>new TenantContext(UserId::fromString((string)$managerId),OrganizationId::fromString($tenant),OrganizationRole::fromString($role),[Permission::fromString(TenantPermissions::ACCESS),Permission::fromString(TenantPermissions::MANAGE)]);
    $manager=$actor($org);
    $observation=$gateway->invoke($manager,'sales.crm.inbox.status',['inbox_id'=>$dead],'trusted_read','',$correlation);
    $check($observation['item']['status']==='DEAD' && $observation['item']['attempts']===10 && !isset($observation['item']['payload']),'Native observation invalid.');
    $input=['inbox_id'=>$dead,'revision'=>$observation['item']['revision'],'reason'=>'mapping_repaired'];
    $key='recovery-'.$token;
    $recover=static fn(array $input,string $key,string $origin='human_api')=>$gateway->invoke($manager,'sales.crm.inbox.recover',$input,$origin,$key,$correlation);
    $denied=static function(callable $fn):void {try{$fn();}catch(Throwable $error){$root=$error;while($root->getPrevious()!==null)$root=$root->getPrevious();if($root instanceof DomainException)return;throw $error;}throw new RuntimeException('Unsafe recovery accepted.');};
    $denied(fn()=>$recover([...$input,'revision'=>str_repeat('0',64)],$key));
    $denied(fn()=>$recover($input,$key,'ai'));
    $denied(fn()=>$gateway->invoke($actor('foreign-'.$token),'sales.crm.inbox.recover',$input,'human_api',$key,$correlation));
    $check($read($dead)['status']==='DEAD','Denied recovery changed the inbox.');
    $receipt=$recover($input,$key);
    $check($receipt===['inbox_id'=>$dead,'requeued'=>true,'replayed'=>false],'Missing requeue receipt.');
    $check($read($dead)['status']==='RECEIVED' && (int)$read($dead)['attempts']===0,'Recovery did not reset the retry budget.');
    $check(in_array($dead,array_column($other->pending(500),'id'),true),'Recovered inbox is not visible to the existing worker sweep.');
    $denied(fn()=>$recover($input,'different-'.$token));
    $denied(fn()=>$recover([...$input,'reason'=>'configuration_repaired'],$key));
    $check($processor->execute($org,$dead,'after-recovery'),'Recovered inbox not processed.');
    $check($recover($input,$key)===[...$receipt,'replayed'=>true] && $read($dead)['status']==='COMPLETED','Recovery replay reset a completed item.');
    $q=$b->prepare("SELECT COUNT(*) FROM cos_audit_log WHERE organization_id=? AND subject_id=? AND action='sales.crm.inbox.recover' AND JSON_UNQUOTE(JSON_EXTRACT(metadata,'$.reason_code'))='mapping_repaired'");
    $q->execute([$org,$dead]);
    $check((int)$q->fetchColumn()===1,'Recovery audit missing or duplicated.');
    echo "Sales CRM Inbox native dedupe, claim fencing, two-connection locking, governed recovery and lifecycle denial PASS.\n";
}finally{
    if($a->inTransaction())$a->rollBack();if($b->inTransaction())$b->rollBack();
    $a->prepare("DELETE FROM sales_operation_receipts WHERE organization_id=? AND operation_type='crm.inbox.recover' AND idempotency_key IN (?,?)")->execute([$org,'recovery-'.$token,'different-'.$token]);
    foreach($ids as $id){
        $a->prepare("DELETE FROM cos_audit_log WHERE organization_id=? AND subject_id=? AND action='sales.crm.inbox.recover'")->execute([$org,$id]);
        $a->prepare('DELETE FROM cos_crm_inbox WHERE organization_id=? AND id=?')->execute([$org,$id]);
    }
    if($managerId!==null){
        $a->prepare('DELETE FROM cos_organization_memberships WHERE organization_id=? AND user_id=?')->execute([$org,$managerId]);
        $a->prepare('DELETE FROM tn_users WHERE id=? AND organization_id=?')->execute([$managerId,$org]);
    }
    if($case!==null){
        $a->prepare('DELETE o FROM cos_event_outbox o INNER JOIN cos_events e ON e.id=o.event_id WHERE e.organization_id=? AND e.aggregate_type=? AND e.aggregate_id=?')->execute([$org,'deal',(string)$case]);
        $a->prepare('DELETE FROM cos_events WHERE organization_id=? AND aggregate_type=? AND aggregate_id=?')->execute([$org,'deal',(string)$case]);
        $a->prepare('DELETE FROM cos_sync_state WHERE organization_id=? AND provider=? AND entity_type=? AND external_id=?')->execute([$org,'aida','deal',(string)$case]);
        $a->prepare('DELETE FROM tn_client_cases WHERE organization_id=? AND id=?')->execute([$org,$case]);
    }
    if($person!==null)$a->prepare('DELETE FROM tn_people WHERE organization_id=? AND id=?')->execute([$org,$person]);
    if($lead!==null){
        $a->prepare('DELETE o FROM cos_event_outbox o INNER JOIN cos_events e ON e.id=o.event_id WHERE e.organization_id=? AND e.aggregate_type=? AND e.aggregate_id=?')->execute([$org,'lead',(string)$lead]);
        $a->prepare('DELETE FROM cos_events WHERE organization_id=? AND aggregate_type=? AND aggregate_id=?')->execute([$org,'lead',(string)$lead]);
        $a->prepare('DELETE FROM cos_sync_state WHERE organization_id=? AND provider=? AND entity_type=? AND external_id=?')->execute([$org,'aida','lead',(string)$lead]);
        $a->prepare('DELETE FROM tn_leads WHERE organization_id=? AND id=?')->execute([$org,$lead]);
    }
}
