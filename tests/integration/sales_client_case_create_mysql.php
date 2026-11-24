<?php
declare(strict_types=1);

use App\Application\Capability\CanonicalCapabilityGateway;
use App\Application\Sales\Command\ConvertSalesLeadToOpportunityCommand;
use App\Application\Sales\Command\CreateSalesLeadCommand;
use App\Application\Sales\Command\ReceivePublicLeadCommand;
use App\Application\Sales\Command\ReceivePublicLeadCommandHandler;
use App\Application\Sales\Command\UpdateSalesLeadCommand;
use App\Application\Sales\Command\UpdateSalesLeadCommandHandler;
use App\Application\Sales\Command\ManageSalesLeadStateCommand;
use App\Application\Sales\Command\ManageSalesLeadStateCommandHandler;
use App\Application\Sales\Command\ManageSalesDealCommand;
use App\Application\Sales\Command\ManageSalesDealCommandHandler;
use App\Infrastructure\Capability\SalesLeadStateManageAdapter;
use App\Infrastructure\Capability\SalesDealStateReadAdapter;
use App\Infrastructure\Capability\SalesDealOwnerAssignAdapter;
use App\Infrastructure\Capability\SalesDealCommercialSetAdapter;
use App\Infrastructure\Capability\SalesPipelineStageManageAdapter;
use App\Infrastructure\Capability\SalesDealOutcomeRecordAdapter;
use Domains\Sales\Application\UseCase\AssignDealOwner;
use Domains\Sales\Application\UseCase\ChangeDealStage;
use Domains\Sales\Application\UseCase\SetDealCommercialTerms;
use Domains\Sales\Domain\Policy\StageTransitionPolicy;
use Domains\Sales\Infrastructure\Persistence\MySql\MysqlDealRepository;
use Domains\Sales\Infrastructure\Persistence\MySql\MysqlPipelineRepository;
use Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesMutationReceiptRepository;
use Infrastructure\Platform\Persistence\MySql\Transaction\TransactionManager;
use Infrastructure\Platform\Persistence\MySql\Event\MysqlEventStore;
use Kernel\Event\EventBus;
use App\Infrastructure\Capability\SalesLeadQualificationAdapter;
use App\Application\Sales\Command\CreateSalesLeadCommandHandler;
use App\Infrastructure\Capability\SalesLeadCreateAdapter;
use App\Application\Sales\Command\ConvertSalesLeadToOpportunityCommandHandler;
use App\Infrastructure\Capability\SalesClientCaseCreateAdapter;
use App\Infrastructure\Messenger\SymfonyCommandBus;
use App\Persistence\Federation\FederationCapabilityBindingResolver;
use Domains\Property\Infrastructure\ReadModel\MySql\MysqlPropertyReferencePort;
use Domains\Sales\Infrastructure\Persistence\MySql\MysqlInboundLeadRepository;
use Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesAssignmentAuthority;
use Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesWriteServiceFactory;
use Domains\Sales\Infrastructure\ReadModel\MySql\MysqlSalesWorkspaceReadModel;
use Kernel\Identity\Model\OrganizationRole;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Module\CanonicalCapabilityCatalog;
use Kernel\Module\Contract\ModuleStateRepositoryInterface;
use Kernel\Module\DomainModuleRegistry;
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

$root = dirname(__DIR__, 2);
require getenv('COS_TEST_AUTOLOAD') ?: $root . '/symfony/vendor/autoload.php';
$check = static function(bool $ok, string $why): void { if (!$ok) throw new RuntimeException($why); };
$reject = static function(callable $fn): void { try { $fn(); } catch (Throwable $error) { $root=$error; while($root->getPrevious()!==null)$root=$root->getPrevious(); if($root instanceof DomainException)return; throw $error; } throw new RuntimeException('Unsafe Sales operation accepted.'); };
$db = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', getenv('DB_HOST') ?: '127.0.0.1', (int)(getenv('DB_PORT') ?: 3306), getenv('DB_DATABASE') ?: 'cos'),
    getenv('DB_USERNAME') ?: 'cos', getenv('DB_PASSWORD') ?: '',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC],
);
$org = (string)$db->query('SELECT p.organization_id FROM sales_pipelines p INNER JOIN sales_pipeline_stages s ON s.id=p.initial_stage_id AND s.organization_id=p.organization_id AND s.pipeline_id=p.id INNER JOIN cos_organizations o ON o.id=p.organization_id WHERE p.status="ACTIVE" AND s.status="ACTIVE" AND o.status="ACTIVE" ORDER BY p.organization_id LIMIT 1')->fetchColumn();
$check($org !== '', 'Migrated MySQL must contain an active organization and initial Sales pipeline.');
$token = bin2hex(random_bytes(8));
$db->beginTransaction();
try {
    $db->prepare('INSERT INTO tn_users (organization_id,email,password_hash,full_name,role,status) VALUES (?,?,?,?,?,?)')
        ->execute([$org,'sales-conversion-'.$token.'@example.test',password_hash($token,PASSWORD_DEFAULT),'Sales CI manager','manager','active']);
    $userId = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO cos_organization_memberships (organization_id,user_id,role,status) VALUES (?,?,?,?)')
        ->execute([$org,$userId,'manager','ACTIVE']);
    $leadId = (new MysqlInboundLeadRepository($db))->create($org,[
        'person_id'=>null,'client_case_id'=>null,'property_id'=>null,'full_name'=>'Sales CI lead',
        'phone'=>null,'email'=>'sales-lead-'.$token.'@example.test','role'=>'buyer','deal_type'=>'consultation',
        'message'=>'private fixture','source_page'=>'/ci',
    ]);

    $factory = new MysqlSalesWriteServiceFactory($db,new MysqlPropertyReferencePort($db),new MysqlSalesAssignmentAuthority($db));
    $tx=new TransactionManager($db);$events=new EventBus(new MysqlEventStore($db),$tx);
    $deals=new MysqlDealRepository($db);$pipelines=new MysqlPipelineRepository($db);
    $receipts=new MysqlSalesMutationReceiptRepository($db);$read=new MysqlSalesWorkspaceReadModel($db);
    $owners=new AssignDealOwner($deals,$events,$tx,new MysqlSalesAssignmentAuthority($db));
    $stages=new ChangeDealStage($deals,$pipelines,new StageTransitionPolicy(),$events,$tx);
    $commercial=new SetDealCommercialTerms($deals,$deals,$events,$tx);
    $handler = new ConvertSalesLeadToOpportunityCommandHandler($factory);
    $bus = new SymfonyCommandBus(new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
        ConvertSalesLeadToOpportunityCommand::class => [$handler],
        CreateSalesLeadCommand::class => [new CreateSalesLeadCommandHandler($factory)],
        ReceivePublicLeadCommand::class => [new ReceivePublicLeadCommandHandler($factory)],
        UpdateSalesLeadCommand::class => [new UpdateSalesLeadCommandHandler($factory)],
        ManageSalesLeadStateCommand::class => [new ManageSalesLeadStateCommandHandler($factory,$read,$receipts,$tx)],
        ManageSalesDealCommand::class => [new ManageSalesDealCommandHandler($deals,$owners,$stages,$commercial,$receipts,$tx)],
    ]))]));
    $module = new ModuleCatalog([ModuleDefinition::fromArray(require $root.'/app/Domains/Sales/module.php')]);
    $catalog = new CanonicalCapabilityCatalog($module);
    $state = new class implements ModuleStateRepositoryInterface {
        public function enabledOverride(string $organizationId,string $moduleId): ?bool { return true; }
        public function setEnabled(string $organizationId,string $moduleId,bool $enabled): void {}
    };
    $active = new ActiveModuleResolver($module,$state);
    $resolver = new FederationCapabilityBindingResolver($catalog,new DomainModuleRegistry([]),$active);
    $read = new MysqlSalesWorkspaceReadModel($db);
    $gateway = new CanonicalCapabilityGateway($catalog,$active,$resolver,[new SalesClientCaseCreateAdapter($bus,$read),new SalesLeadCreateAdapter($bus,$read),new SalesLeadQualificationAdapter($bus,$read),new SalesLeadStateManageAdapter($bus),new SalesDealStateReadAdapter($deals),new SalesDealOwnerAssignAdapter($bus),new SalesDealCommercialSetAdapter($bus),new SalesPipelineStageManageAdapter($bus),new SalesDealOutcomeRecordAdapter($bus)]);
    $actor = static fn(string $tenant,string $role='manager') => new TenantContext(
        UserId::fromString((string)$userId),OrganizationId::fromString($tenant),OrganizationRole::fromString($role),
        [Permission::fromString(TenantPermissions::ACCESS),Permission::fromString(TenantPermissions::MANAGE)],
    );
    $cap = 'sales.client_case.create';
    $input = ['lead_id'=>$leadId];
    $manager = $actor($org);
    $reject(fn()=>$gateway->invoke($actor('foreign-'.$token),$cap,$input,'human_api','key','trace'));
    $reject(fn()=>$gateway->invoke($actor($org,'viewer'),$cap,$input,'human_api','key','trace'));
    $reject(fn()=>$gateway->invoke($manager,$cap,$input,'ai','key','trace'));
    $check(empty($read->lead($org,$leadId)['client_case_id']),'Denied conversion mutated native lead.');
    $receipt = $gateway->invoke($manager,$cap,$input,'human_api','first-'.$token,'trace-'.$token);
    $caseId = $receipt['case_id'];
    $check($receipt===['lead_id'=>$leadId,'case_id'=>$caseId,'replayed'=>false] && $caseId>0,'Invalid conversion receipt.');
    $repeat = $gateway->invoke($manager,$cap,$input,'human_ui','second-'.$token,'trace-replay-'.$token);
    $check($repeat===[...$receipt,'replayed'=>true],'Replay did not return original case.');
    $lead = $read->lead($org,$leadId);
    $case = $read->deal($org,$caseId);
    $check((int)$lead['client_case_id']===$caseId && $lead['status']==='qualified' && (int)$case['assigned_user_id']===$userId,'Native lead/case state mismatch.');
    $q = $db->prepare('SELECT COUNT(*) FROM tn_client_cases WHERE organization_id=? AND inbound_request_id=?');
    $q->execute([$org,$leadId]);
    $check((int)$q->fetchColumn()===1,'Duplicate native case.');
    $q = $db->prepare('SELECT COUNT(*) FROM cos_events e INNER JOIN cos_event_outbox o ON o.event_id=e.id AND o.organization_id=e.organization_id WHERE e.organization_id=? AND e.aggregate_id=? AND e.type=?');
    $q->execute([$org,(string)$caseId,'sales.client_case.created']);
    $check((int)$q->fetchColumn()===1,'Expected exactly one case-created Event/Outbox.');
    $intake = ['full_name'=>'Intake '.$token,'email'=>'intake-'.$token.'@example.test'];
    $intakeKey = 'intake-'.$token;
    $new = $gateway->invoke($manager,'sales.lead.create',$intake,'human_api',$intakeKey,'trace-intake');
    $newId = $new['lead_id'];
    $check($new===['lead_id'=>$newId,'replayed'=>false] && $newId>0,'Intake failed.');
    $check($gateway->invoke($manager,'sales.lead.create',array_reverse($intake,true),'human_ui',$intakeKey,'trace-replay')===[...$new,'replayed'=>true],'Equivalent intake did not replay.');
    $reject(fn()=>$gateway->invoke($manager,'sales.lead.create',[...$intake,'full_name'=>'Changed'],'human_api',$intakeKey,'trace-conflict'));
    $reject(fn()=>$gateway->invoke($manager,'sales.lead.create',[...$intake,'source'=>'fake-crm'],'human_api','other-key','trace'));
    $reject(fn()=>$gateway->invoke($manager,'sales.lead.create',[...$intake,'property_id'=>PHP_INT_MAX],'human_api','other-key','trace'));
    $reject(fn()=>$gateway->invoke($actor('foreign-'.$token),'sales.lead.create',$intake,'human_api',$intakeKey,'trace-foreign'));
    $reject(fn()=>$gateway->invoke($manager,'sales.lead.create',$intake,'ai','other-key','trace'));
    $reject(fn()=>$gateway->invoke($actor($org,'viewer'),'sales.lead.create',$intake,'human_api','other-key','trace'));
    $q=$db->prepare('SELECT COUNT(*) FROM tn_leads WHERE organization_id=? AND email=?');
    $q->execute([$org,$intake['email']]);
    $check((int)$q->fetchColumn()===1,'Intake replay or conflict duplicated native lead.');
    $q=$db->prepare('SELECT COUNT(*) FROM cos_events e INNER JOIN cos_event_outbox o ON o.event_id=e.id WHERE e.organization_id=? AND e.aggregate_id=? AND e.type=?');
    $q->execute([$org,(string)$newId,'sales.lead.created']);
    $check((int)$q->fetchColumn()===1,'Intake replay duplicated event/outbox.');
    $legacyKey='legacy-'.$token;
    $db->prepare('INSERT INTO sales_operation_receipts(organization_id,operation_type,idempotency_key,mutation_id) VALUES(?,?,?,?)')->execute([$org,'api.lead.create',$legacyKey,(string)$newId]);
    $reject(fn()=>$gateway->invoke($manager,'sales.lead.create',$intake,'human_api',$legacyKey,'trace-legacy'));
    $public=['submission_id'=>bin2hex(random_bytes(16)),'full_name'=>'Public '.$token,'email'=>'public-'.$token.'@example.test','request_intent'=>'viewing'];
    $submit=static fn(array $data)=>$bus->dispatch(new ReceivePublicLeadCommand(OrganizationId::fromString($org),$data,'/contacts'));
    $accepted=$submit($public);
    $check($accepted->ok && (int)$accepted->data['lead_id']>0,'Public intake failed.');
    $publicId=(int)$accepted->data['lead_id'];
    $again=$submit(array_reverse($public,true));
    $check($again->ok && (int)$again->data['lead_id']===$publicId,'Public replay created a different lead.');
    $check(!$submit([...$public,'full_name'=>'Different'])->ok,'Conflicting public submission accepted.');
    $check(!$submit(array_diff_key($public,['submission_id'=>true]))->ok,'Missing public submission identity accepted.');
    $row=$read->lead($org,$publicId);
    $check($row['request_intent']==='viewing' && (int)$row['client_case_id']>0,'Public intent or case link missing.');
    $q=$db->prepare('SELECT COUNT(*) FROM tn_leads WHERE organization_id=? AND email=?');$q->execute([$org,$public['email']]);
    $check((int)$q->fetchColumn()===1,'Public replay duplicated the native lead.');
    $q=$db->prepare('SELECT COUNT(*) FROM tn_client_cases WHERE organization_id=? AND person_id=?');$q->execute([$org,(int)$row['person_id']]);
    $check((int)$q->fetchColumn()===1,'Public replay duplicated the case.');
    $q=$db->prepare('SELECT COUNT(*) FROM cos_events e INNER JOIN cos_event_outbox o ON o.event_id=e.id WHERE e.organization_id=? AND e.aggregate_id=? AND e.type=?');
    $q->execute([$org,(string)$publicId,'sales.lead.created']);
    $check((int)$q->fetchColumn()===1,'Public replay duplicated event/outbox.');
    $q->execute([$org,(string)$row['client_case_id'],'sales.client_case.created']);
    $check((int)$q->fetchColumn()===1,'Public replay duplicated case event/outbox.');
    $other=$submit([...$public,'submission_id'=>bin2hex(random_bytes(16))]);
    $check($other->ok && (int)$other->data['lead_id']!==$publicId,'New independent enquiry was suppressed.');
    $db->prepare('UPDATE tn_leads SET next_contact_at=? WHERE organization_id=? AND id=?')->execute(['2030-01-01 10:00:00',$org,$newId]);
    $qualify=static fn(array $data,string $key,string $origin='human_api')=>$gateway->invoke($manager,'sales.lead.qualify',$data,$origin,$key,'trace-qualification');
    $step=['lead_id'=>$newId,'expected_status'=>'new','target_status'=>'contacted'];
    $reject(fn()=>$qualify($step,'ai','ai'));
    $check($qualify($step,'contact')===['lead_id'=>$newId,'status'=>'contacted','replayed'=>false],'Qualification not recorded.');
    $check($qualify($step,'contact-repeat')===['lead_id'=>$newId,'status'=>'contacted','replayed'=>true],'Qualification replay not recognized.');
    $check($read->lead($org,$newId)['next_contact_at']==='2030-01-01 10:00:00','Qualification erased next contact.');
    $reject(fn()=>$qualify([...$step,'target_status'=>'qualified'],'stale'));
    $check($qualify([...$step,'expected_status'=>'contacted','target_status'=>'qualified'],'qualify')['status']==='qualified','Qualification progression failed.');
    $check($qualify([...$step,'expected_status'=>'qualified','target_status'=>'disqualified'],'disqualify')['status']==='disqualified','Disqualification failed.');
    $reject(fn()=>$gateway->invoke($manager,'sales.client_case.create',['lead_id'=>$newId],'human_api','terminal','trace'));
    $q=$db->prepare('SELECT COUNT(*) FROM cos_events e INNER JOIN cos_event_outbox o ON o.event_id=e.id WHERE e.organization_id=? AND e.aggregate_id=? AND e.type=?');
    foreach(['sales.lead.contacted','sales.lead.qualified','sales.lead.disqualified'] as $type){
        $q->execute([$org,(string)$newId,$type]);$check((int)$q->fetchColumn()===1,'Missing or duplicate qualification event '.$type);
    }
    $team=(string)$db->query("SELECT id FROM sales_teams WHERE organization_id=".$db->quote($org)." AND status='ACTIVE' ORDER BY id LIMIT 1")->fetchColumn();
    $check($team!=='','No active Sales team.');
    $db->prepare("INSERT INTO sales_team_members(organization_id,team_id,user_id,role,status,assignment_enabled,approval_enabled,assignment_weight,created_at,updated_at) VALUES (?,?,?,'LEAD','ACTIVE',1,1,1,NOW(6),NOW(6))")->execute([$org,$team,$userId]);
    $db->prepare("INSERT INTO sales_user_capabilities(organization_id,user_id,capability,status,granted_by,created_at,updated_at) VALUES (?,?,'sales.deal.assign','ACTIVE',?,NOW(6),NOW(6))")->execute([$org,$userId,(string)$userId]);
    $db->prepare('INSERT INTO tn_users (organization_id,email,password_hash,full_name,role,status) VALUES (?,?,?,?,?,?)')->execute([$org,'sales-owner-'.$token.'@example.test',password_hash($token,PASSWORD_DEFAULT),'Sales CI owner','manager','active']);
    $targetOwner=(int)$db->lastInsertId();
    $db->prepare('INSERT INTO cos_organization_memberships (organization_id,user_id,role,status) VALUES (?,?,?,?)')->execute([$org,$targetOwner,'manager','ACTIVE']);
    $db->prepare("INSERT INTO sales_team_members(organization_id,team_id,user_id,role,status,assignment_enabled,approval_enabled,assignment_weight,created_at,updated_at) VALUES (?,?,?,'MEMBER','ACTIVE',1,0,1,NOW(6),NOW(6))")->execute([$org,$team,$targetOwner]);
    $snapshot=static fn(int $id)=>$gateway->invoke($manager,'sales.deal.state.read',['deal_id'=>$id],'trusted_read','','trace-state')['state'];
    $invoke=static fn(string $cap,array $data,string $key,string $origin='human_api')=>$gateway->invoke($manager,$cap,$data,$origin,$key,'trace-deal');
    $state=$snapshot($caseId);$base=['deal_id'=>$caseId,'expected_revision'=>$state['revision']];
    $assign=[...$base,'owner_id'=>$targetOwner];
    $reject(fn()=>$invoke('sales.deal.owner.assign',[...$base,'owner_id'=>PHP_INT_MAX],'invalid-owner'));
    $reject(fn()=>$invoke('sales.deal.owner.assign',$assign,'ai','ai'));
    $assigned=$invoke('sales.deal.owner.assign',$assign,'owner');
    $check($assigned['changed'] && $assigned['state']['owner_id']===$targetOwner,'Assignment not persisted.');
    $check($invoke('sales.deal.owner.assign',$assign,'owner')['replayed'],'Assignment replay missing.');
    $reject(fn()=>$invoke('sales.deal.owner.assign',[...$assign,'owner_id'=>$userId],'owner'));
    $reject(fn()=>$invoke('sales.deal.commercial.set',[...$base,'deal_value'=>'1000.00','currency'=>'EUR'],'stale'));
    $state=$snapshot($caseId);$terms=['deal_id'=>$caseId,'expected_revision'=>$state['revision'],'deal_value'=>'123456.78','currency'=>'EUR'];
    $reject(fn()=>$invoke('sales.deal.commercial.set',[...$terms,'deal_value'=>'-1.00'],'invalid-value'));
    $termsReceipt=$invoke('sales.deal.commercial.set',$terms,'terms');
    $check($termsReceipt['state']['deal_value']==='123456.78' && $termsReceipt['state']['currency']==='EUR','Exact terms missing.');
    $check($invoke('sales.deal.commercial.set',$terms,'terms')['replayed'],'Commercial replay failed.');
    $pipeline=$pipelines->getPipeline($org,$state['pipeline_id']);$lost=null;$won=null;
    foreach($pipeline->stages as $stage){if($stage->isLost)$lost=$stage;if($stage->isWon)$won=$stage;}
    $check($lost!==null && $won!==null,'Terminal stages missing.');
    $reason=$pipelines->defaultLostReasonId($org,$pipeline->id);
    $state=$snapshot($caseId);
    $base=['deal_id'=>$caseId,'expected_revision'=>$state['revision']];
    $reject(fn()=>$invoke('sales.pipeline.stage.manage',[...$base,'target_stage_id'=>$lost->id],'terminal-stage'));
    $reject(fn()=>$invoke('sales.deal.outcome.record',[...$base,'target_stage_id'=>$lost->id,'outcome'=>'lost'],'missing-reason'));
    $reject(fn()=>$invoke('sales.deal.outcome.record',[...$base,'target_stage_id'=>$lost->id,'outcome'=>'lost','lost_reason_id'=>'invalid'],'bad-reason'));
    $lostInput=[...$base,'target_stage_id'=>$lost->id,'outcome'=>'lost','lost_reason_id'=>$reason];
    $lostReceipt=$invoke('sales.deal.outcome.record',$lostInput,'lost');
    $check($lostReceipt['state']['status']==='lost' && $lostReceipt['state']['lost_reason_id']===$reason,'LOST native outcome mismatch.');
    $check($invoke('sales.deal.outcome.record',$lostInput,'lost')['replayed'],'LOST receipt replay failed.');
    $terminal=$snapshot($caseId);
    $reject(fn()=>$invoke('sales.deal.commercial.set',['deal_id'=>$caseId,'expected_revision'=>$terminal['revision'],'deal_value'=>'1.00','currency'=>'USD'],'after-close'));
    $reject(fn()=>$invoke('sales.deal.owner.assign',['deal_id'=>$caseId,'expected_revision'=>$terminal['revision'],'owner_id'=>$userId],'after-close'));
    $q=$db->prepare('SELECT COUNT(*) FROM cos_events e INNER JOIN cos_event_outbox o ON o.event_id=e.id WHERE e.organization_id=? AND e.aggregate_id=? AND e.type=?');
    foreach(['sales.deal.owner_assigned','sales.deal.lost'] as $type){$q->execute([$org,(string)$caseId,$type]);$check((int)$q->fetchColumn()===1,'Missing or duplicate '.$type);}
    // Full lifecycle classification uses its own durable receipt and cannot implicitly close a Deal.
    $lifecycle=['lead_id'=>$leadId,'expected_status'=>'qualified','target_status'=>'lost'];
    $check($invoke('sales.lead.state.manage',$lifecycle,'lead-lost')['status']==='lost','Lead did not reflect independently lost Deal.');
    $check($invoke('sales.lead.state.manage',$lifecycle,'lead-lost')['replayed'],'Lead lifecycle receipt missing.');
    // Traverse real configured nonterminal transitions and verify positive WON.
    $wonCase=(int)$row['client_case_id'];$current=$snapshot($wonCase);
    $assignWon=['deal_id'=>$wonCase,'expected_revision'=>$current['revision'],'owner_id'=>$userId];
    $invoke('sales.deal.owner.assign',$assignWon,'owner-won');
    $current=$snapshot($wonCase);
    $invoke('sales.deal.commercial.set',['deal_id'=>$wonCase,'expected_revision'=>$current['revision'],'deal_value'=>'999.99','currency'=>'USD'],'terms-won');
    $current=$snapshot($wonCase);
    $pdef=$pipelines->getPipeline($org,$current['pipeline_id']);
    $queue=[[$current['stage_id'],[]]];$visited=[];$route=null;
    while($queue!==[]){[$from,$path]=array_shift($queue);if(isset($visited[$from]))continue;$visited[$from]=true;if($from===$won->id){$route=$path;break;}foreach($pdef->stages as $stage){if($stage->isLost||isset($visited[$stage->id]))continue;$transition=$pipelines->getTransition($org,$pdef->id,$from,$stage->id);if($transition!==null&&$transition->conditions===[])$queue[]=[$stage->id,[...$path,$stage->id]];}}
    $check(is_array($route) && $route!==[],'Default pipeline has no configured path to WON.');
    foreach($route as $index=>$next){
        $current=$snapshot($wonCase);$payload=['deal_id'=>$wonCase,'expected_revision'=>$current['revision'],'target_stage_id'=>$next];
        if($next===$won->id){
            $result=$invoke('sales.deal.outcome.record',[...$payload,'outcome'=>'won'],'won');
            $check($result['state']['status']==='closed'&&$result['state']['deal_value']==='999.99','WON native state mismatch.');
            $check($invoke('sales.deal.outcome.record',[...$payload,'outcome'=>'won'],'won')['replayed'],'WON replay failed.');
        }else{$check($invoke('sales.pipeline.stage.manage',$payload,'stage-'.$index)['state']['stage_id']===$next,'Nonterminal stage transition failed.');}
    }
    $q->execute([$org,(string)$wonCase,'sales.deal.won']);$check((int)$q->fetchColumn()===1,'WON event/outbox missing or duplicated.');
    echo "Sales canonical intake, lifecycle, ownership, commercial terms, pipeline and WON/LOST native state and outbox PASS.\n";
} finally {
    if ($db->inTransaction()) $db->rollBack();
}
