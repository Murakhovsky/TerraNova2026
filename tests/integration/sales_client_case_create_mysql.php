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
use App\Application\Sales\Command\ManageSalesActivityCommand;
use App\Application\Sales\Command\ManageSalesActivityCommandHandler;
use App\Infrastructure\Capability\SalesActivityRecordAdapter;
use App\Infrastructure\Capability\SalesActivityCompleteAdapter;
use App\Infrastructure\Capability\SalesActivityRescheduleAdapter;
use App\Infrastructure\Capability\SalesFollowupScheduleAdapter;
use App\Infrastructure\Capability\SalesPropertyMatchRecordAdapter;
use Domains\Sales\Application\Service\SalesOperationService;
use Domains\Sales\Application\Contract\MessageGatewayInterface;
use Domains\Sales\Application\DTO\SendMessageCommand;
use Domains\Sales\Application\DTO\OperationResult;
use Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesOperationRepository;
use Domains\Sales\Infrastructure\ReadModel\MySql\MysqlSalesActivityEvidenceReadModel;
use App\Application\Sales\Command\ManageSalesDealCommandHandler;
use App\Application\Sales\Command\ManageSalesTeamCommand;
use App\Application\Sales\Command\ManageSalesTeamCommandHandler;
use App\Application\Sales\Admin\SalesAdminAuthorization;
use App\Infrastructure\Capability\SalesTeamManageAdapter;
use App\Infrastructure\Capability\SalesTeamReadAdapter;
use Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesTeamAdministration;
use Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesAccessControl;
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
    $teams=new MysqlSalesTeamAdministration($db);$teamAuth=new SalesAdminAuthorization(new MysqlSalesAccessControl($db));
    $activityEvidence=new MysqlSalesActivityEvidenceReadModel($db);
    $messageGateway=new class implements MessageGatewayInterface{
        public function send(SendMessageCommand $command):OperationResult{throw new RuntimeException('Unexpected external delivery.');}
    };
    $operations=new SalesOperationService($messageGateway,new MysqlSalesOperationRepository($db),$events,$tx);
    $activityHandler=new ManageSalesActivityCommandHandler($factory,$operations,$deals,$activityEvidence,$receipts,new MysqlPropertyReferencePort($db),$tx);
    $handler = new ConvertSalesLeadToOpportunityCommandHandler($factory);
    $module = new ModuleCatalog(array_map(static fn(string $d)=>ModuleDefinition::fromArray(require $root.'/app/Domains/'.$d.'/module.php'),['Sales','ProductManagement','Property','Finance']));
    $catalog = new CanonicalCapabilityCatalog($module);
    $state = new class implements ModuleStateRepositoryInterface {
        public function enabledOverride(string $organizationId,string $moduleId): ?bool { return true; }
        public function setEnabled(string $organizationId,string $moduleId,bool $enabled): void {}
    };
    $active = new ActiveModuleResolver($module,$state);
    $offerRepo=new \Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesOfferRepository($db);
    $productRef=new \Domains\ProductManagement\Application\Service\ProductCommercialReference(new \Domains\ProductManagement\Infrastructure\Persistence\MySqlProductManagementStore($db,new MysqlEventStore($db)));
    $offerRefs=new \App\Application\Sales\SalesOfferReferenceResolver($productRef,new MysqlPropertyReferencePort($db),$active);
    $offerService=new \Domains\Sales\Application\Service\SalesOfferService($offerRepo,$offerRefs,$deals,$commercial,$receipts,$events,$tx);
    $scheduledRepo=new \Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesScheduledActivityRepository($db);
    $leadFollowup=new \Domains\Sales\Application\UseCase\ScheduleLeadFollowup(new \Domains\Sales\Infrastructure\Persistence\MySql\MysqlLeadFollowupRepository($db),$events,$tx);
    $scheduledService=new \Domains\Sales\Application\Service\SalesScheduledActivityService($scheduledRepo,$leadFollowup,$receipts,$events,$tx);
    $notificationDb=new \Infrastructure\Platform\Persistence\Pdo\PdoConnection($db);
    $notifications=new \Platform\Notification\Service\NotificationDispatcher(
        [new \Infrastructure\Platform\Notification\N8nEmailNotificationChannel($notificationDb)],
        new \Infrastructure\Platform\Notification\BuiltinNotificationTemplateRepository(),
        new \Infrastructure\Platform\Notification\SimpleNotificationTemplateRenderer(),
        new \Infrastructure\Platform\Notification\MysqlNotificationDeliveryRepository($notificationDb));
    $handoff=new \Infrastructure\Platform\Notification\MysqlNotificationHandoffReadModel($db);
    $deliveryRepo=new \Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesOfferDeliveryRepository($db);
    $offerDelivery=new \Domains\Sales\Application\Service\SalesOfferDeliveryService($offerService,$deliveryRepo,$deals,$offerRefs,$notifications,$handoff,$tx,$events);
    $reminders=new \Domains\Sales\Application\Service\SalesActivityReminderService($scheduledRepo,$receipts,$events,$tx,$active,$notifications,$handoff);
    $referenceStore=new \Infrastructure\Platform\Persistence\MySql\MysqlExternalReferenceStore($db);
    $crmEvidence=new \Domains\Sales\Infrastructure\ReadModel\MySql\MysqlSalesCrmReconciliationReadModel($db,$referenceStore);
    $nativeMessages=new SalesOperationService(new \Domains\Sales\Infrastructure\Persistence\MySql\MysqlMessageGateway($db,$referenceStore),new MysqlSalesOperationRepository($db),$events,$tx);
    $bus = new SymfonyCommandBus(new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
        ConvertSalesLeadToOpportunityCommand::class => [$handler],
        CreateSalesLeadCommand::class => [new CreateSalesLeadCommandHandler($factory)],
        ReceivePublicLeadCommand::class => [new ReceivePublicLeadCommandHandler($factory)],
        UpdateSalesLeadCommand::class => [new UpdateSalesLeadCommandHandler($factory)],
        ManageSalesLeadStateCommand::class => [new ManageSalesLeadStateCommandHandler($factory,$read,$receipts,$tx)],
        ManageSalesDealCommand::class => [new ManageSalesDealCommandHandler($deals,$owners,$stages,$commercial,$receipts,$tx)],
        ManageSalesTeamCommand::class => [new ManageSalesTeamCommandHandler($teams,$teamAuth,$receipts,$tx)],
        ManageSalesActivityCommand::class => [$activityHandler],
        \App\Application\Sales\Command\ManageScheduledSalesActivityCommand::class => [new \App\Application\Sales\Command\ManageScheduledSalesActivityCommandHandler($scheduledService)],
        \App\Application\Sales\Command\ManageSalesOfferCommand::class => [new \App\Application\Sales\Command\ManageSalesOfferCommandHandler($offerService)],
    ]))]));
    $resolver = new FederationCapabilityBindingResolver($catalog,new DomainModuleRegistry([]),$active);
    $read = new MysqlSalesWorkspaceReadModel($db);
    $gateway = new CanonicalCapabilityGateway($catalog,$active,$resolver,[new SalesClientCaseCreateAdapter($bus,$read),new SalesLeadCreateAdapter($bus,$read),new SalesLeadQualificationAdapter($bus,$read),new SalesLeadStateManageAdapter($bus),new SalesDealStateReadAdapter($deals),new SalesDealOwnerAssignAdapter($bus),new SalesDealCommercialSetAdapter($bus),new SalesPipelineStageManageAdapter($bus),new SalesDealOutcomeRecordAdapter($bus),new SalesTeamManageAdapter($bus),new SalesTeamReadAdapter($teams,$teamAuth),new SalesActivityRecordAdapter($bus),new SalesActivityCompleteAdapter($bus),new SalesActivityRescheduleAdapter($bus),new SalesFollowupScheduleAdapter($bus),new SalesPropertyMatchRecordAdapter($bus),new \App\Infrastructure\Capability\SalesLeadIntakeAdapter($bus,$read),new \App\Infrastructure\Capability\SalesOfferCreateAdapter($bus),new \App\Infrastructure\Capability\SalesOfferReviseAdapter($bus),new \App\Infrastructure\Capability\SalesOfferTransitionAdapter($bus),new \App\Infrastructure\Capability\SalesNegotiationRecordAdapter($bus),new \App\Infrastructure\Capability\SalesOfferReadAdapter($offerRepo),new \App\Infrastructure\Capability\SalesOfferListAdapter($offerRepo),new \App\Infrastructure\Capability\FinanceSalesCommercialEvidenceReadAdapter($offerRepo,$active),new \App\Infrastructure\Capability\SalesLeadFollowupScheduleAdapter($bus),new \App\Infrastructure\Capability\SalesLeadActivityCompleteAdapter($bus),new \App\Infrastructure\Capability\SalesLeadActivityRescheduleAdapter($bus),new \App\Infrastructure\Capability\SalesLeadActivityCancelAdapter($bus),new \App\Infrastructure\Capability\SalesActivityCancelAdapter($bus),new \App\Infrastructure\Capability\SalesActivityOverdueReadAdapter($scheduledRepo),new \App\Infrastructure\Capability\SalesOfferDocumentRenderAdapter($offerDelivery),new \App\Infrastructure\Capability\SalesOfferDeliveryStatusAdapter($offerDelivery),new \App\Infrastructure\Capability\SalesCrmReconciliationReadAdapter($crmEvidence)]);
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
    // Real operator activities and projection: earliest outstanding followup survives unrelated call.
    $due1='2035-01-01T10:00:00+00:00';$due2='2035-01-02T10:00:00+00:00';$due3='2035-01-03T10:00:00+00:00';
    $first=['deal_id'=>$caseId,'title'=>'First followup','due_at'=>$due1];
    $second=['deal_id'=>$caseId,'title'=>'Second followup','due_at'=>$due2];
    $f1=$invoke('sales.followup.schedule',$first,'followup-1');$f2=$invoke('sales.followup.schedule',$second,'followup-2');
    $check($f1['record_id']!==$f2['record_id']&&$invoke('sales.followup.schedule',$first,'followup-1')['replayed'],'Followup identity or replay failed.');
    $reject(fn()=>$invoke('sales.followup.schedule',[...$first,'title'=>'Changed'],'followup-1'));
    $activityProof=new \Domains\Sales\Application\Service\SalesActivityOutcomeEvidence($activityEvidence,$receipts);
    $verifyActivity=static fn(string $type,array $p,string $key)=>$activityProof->verify($org,$type,$userId,(string)$caseId,$key,$p);
    $check($verifyActivity('sales.governed.followup.schedule',$first,'followup-1')==='sales:activity:'.$f1['record_id'].':followup-1','Independent followup proof missing.');
    $reject(fn()=>$verifyActivity('sales.governed.followup.schedule',[...$first,'title'=>'tampered'],'followup-1'));
    $reject(fn()=>$activityProof->verify('foreign-'.$org,'sales.governed.followup.schedule',$userId,(string)$caseId,'followup-1',$first));
    $reject(fn()=>$activityProof->verify($org,'sales.governed.followup.schedule',$targetOwner,(string)$caseId,'followup-1',$first));
    $expectedDate=static fn(string $s)=>(new DateTimeImmutable($s))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
    $check($read->deal($org,$caseId)['next_contact_at']===$expectedDate($due1),'Earliest followup projection failed.');
    $call=['deal_id'=>$caseId,'activity_type'=>'call','title'=>'Completed call','completed'=>true,'duration_seconds'=>42,'call_result'=>'answered'];
    $callReceipt=$invoke('sales.activity.record',$call,'call');
    $check($callReceipt['status']==='completed'&&$invoke('sales.activity.record',$call,'call')['replayed'],'Completed call replay failed.');
    $check($verifyActivity('sales.governed.activity.record',$call,'call')==='sales:activity:'.$callReceipt['record_id'].':call','Independent activity record proof missing.');
    $check($read->deal($org,$caseId)['next_contact_at']===$expectedDate($due1),'Call erased pending followup.');
    $reschedule=['deal_id'=>$caseId,'activity_id'=>$f1['record_id'],'due_at'=>$due3];
    $invoke('sales.activity.reschedule',$reschedule,'move');
    $check($verifyActivity('sales.governed.activity.reschedule',$reschedule,'move')==='sales:activity:'.$f1['record_id'].':move','Independent reschedule proof missing.');
    $reject(fn()=>$verifyActivity('sales.governed.followup.schedule',$first,'followup-1'));
    $check($read->deal($org,$caseId)['next_contact_at']===$expectedDate($due2),'Reschedule did not recompute next contact.');
    $complete=['deal_id'=>$caseId,'activity_id'=>$f2['record_id']];
    $check($invoke('sales.activity.complete',$complete,'complete')['status']==='completed','Completion failed.');
    $check($invoke('sales.activity.complete',$complete,'complete')['replayed'],'Completion replay failed.');
    $check($verifyActivity('sales.governed.activity.complete',$complete,'complete')==='sales:activity:'.$f2['record_id'].':complete','Independent completion proof missing.');
    $check($read->deal($org,$caseId)['next_contact_at']===$expectedDate($due3),'Completion did not preserve remaining followup.');
    $reject(fn()=>$invoke('sales.activity.reschedule',['deal_id'=>$caseId,'activity_id'=>$f2['record_id'],'due_at'=>$due1],'completed'));
    $reject(fn()=>$invoke('sales.property_match.record',['deal_id'=>$caseId,'property_id'=>PHP_INT_MAX,'match_status'=>'suggested'],'no-property'));
    $q=$db->prepare('SELECT COUNT(*) FROM cos_events e INNER JOIN cos_event_outbox o ON o.event_id=e.id WHERE e.organization_id=? AND e.aggregate_id=? AND e.type=?');
    $q->execute([$org,(string)$caseId,'sales.call.completed']);$check((int)$q->fetchColumn()===1,'Call event missing or duplicated.');
    // S3: schedule, reschedule, cancel and complete native lead tasks.
    $db->prepare('UPDATE tn_leads SET assigned_user_id=? WHERE organization_id=? AND id=?')->execute([$userId,$org,$leadId]);
    $leadSchedule=['lead_id'=>$leadId,'title'=>'Lead callback','due_at'=>$due1];
    $la=$invoke('sales.lead.followup.schedule',$leadSchedule,'lead-task-one');
    $check($la['status']==='open'&&$invoke('sales.lead.followup.schedule',$leadSchedule,'lead-task-one')['replayed'],'Lead followup receipt failed.');
    $lb=$invoke('sales.lead.followup.schedule',[...$leadSchedule,'title'=>'Second callback','due_at'=>$due2],'lead-task-two');
    $check($read->lead($org,$leadId)['next_contact_at']===$expectedDate($due1),'Lead earliest contact incorrect.');
    $invoke('sales.lead.activity.reschedule',['lead_id'=>$leadId,'activity_id'=>$la['activity_id'],'due_at'=>$due3],'lead-move');
    $check($read->lead($org,$leadId)['next_contact_at']===$expectedDate($due2),'Lead next contact not recomputed.');
    $cancelLead=['lead_id'=>$leadId,'activity_id'=>$lb['activity_id'],'reason'=>'Customer requested later'];
    $check($invoke('sales.lead.activity.cancel',$cancelLead,'lead-cancel')['status']==='cancelled','Lead cancellation failed.');
    $check($invoke('sales.lead.activity.cancel',$cancelLead,'lead-cancel')['replayed'],'Lead cancellation replay failed.');
    $reject(fn()=>$invoke('sales.lead.activity.complete',['lead_id'=>$leadId,'activity_id'=>$lb['activity_id']],'cancelled-complete'));
    $check($read->lead($org,$leadId)['next_contact_at']===$expectedDate($due3),'Cancelled task polluted lead projection.');
    // Backdate a fixture only, then exercise actual overdue read and existing scan boundary.
    $past='2020-01-01 00:00:00';
    $db->prepare('UPDATE tn_lead_activities SET due_at=? WHERE id=? AND lead_id=?')->execute([$past,$la['activity_id'],$leadId]);
    $due=$gateway->invoke($manager,'sales.activity.overdue.read',['limit'=>200],'trusted_read','','due-read');
    $check(count(array_filter($due['items'],fn(array $x)=>$x['target_type']==='lead'&&$x['activity_id']===$la['activity_id']))===1,'Overdue native lead absent.');
    $reminders->scan($org,new DateTimeImmutable(),200);$reminders->scan($org,new DateTimeImmutable(),200);
    $q=$db->prepare('SELECT COUNT(*) FROM cos_events e INNER JOIN cos_event_outbox o ON o.event_id=e.id WHERE e.organization_id=? AND e.aggregate_id=? AND e.type=?');
    $q->execute([$org,(string)$leadId,'sales.activity.reminder.due']);$check((int)$q->fetchColumn()===1,'Reminder event missing or duplicated.');
    $reminderParams=['activity_id'=>$la['activity_id'],'expected_due_at'=>$past,'expected_owner_id'=>$userId];
    $reject(fn()=>$gateway->invoke($manager,'sales.activity.reminder.queue',['target_type'=>'lead','target_id'=>(string)$leadId,'parameters'=>$reminderParams],'human_api','denied','trace'));
    $reminderAction=new \Kernel\Action\Action(bin2hex(random_bytes(16)),$org,'sales.activity.reminder.queue','lead',(string)$leadId,$reminderParams,'USER',(string)$userId,'APPROVAL_REQUIRED','HIGH','reminder-action',new DateTimeImmutable(),\Kernel\Action\ActionStatus::Running,'reminder-trace');
    $reminderHandler=new \Domains\Sales\Automation\Action\QueueSalesActivityReminderHandler($reminders);
    $reminder=$reminderHandler->execute($reminderAction);
    $check($reminder->successful&&$reminder->data['state']==='queued'&&$reminder->data['recipient_delivery_verified']===false,'Reminder queue evidence invalid.');
    $check($reminderHandler->execute($reminderAction)->data['notification_id']===$reminder->data['notification_id'],'Reminder replay duplicated queue.');
    $check($invoke('sales.lead.activity.complete',['lead_id'=>$leadId,'activity_id'=>$la['activity_id']],'lead-complete')['status']==='completed','Lead task completion failed.');
    $check($read->lead($org,$leadId)['next_contact_at']===null,'Completed lead task left next contact.');
    $check(!$reminderHandler->execute($reminderAction)->successful,'Completed task still eligible for reminder.');
    $cancelDeal=['deal_id'=>$caseId,'activity_id'=>$f1['record_id'],'reason'=>'No longer needed'];
    $check($invoke('sales.activity.cancel',$cancelDeal,'deal-cancel')['status']==='cancelled','Deal cancellation failed.');
    $check($read->deal($org,$caseId)['next_contact_at']===null,'Cancelled deal followup polluted next contact.');
    $reject(fn()=>$invoke('sales.activity.complete',['deal_id'=>$caseId,'activity_id'=>$f1['record_id']],'complete-cancelled'));
    // Native local CRM recording and read-only reconciliation, never external delivery.
    $msg=new SendMessageCommand($org,(string)$caseId,'EMAIL','CI CRM record','crm-'.$token);
    $m=$nativeMessages->sendMessage($msg,[],'USER',(string)$userId);
    $check($m->successful&&$m->data['delivery_state']==='local_recorded'&&$m->data['recipient_delivery_verified']===false,'Local CRM log represented as delivery.');
    $check($nativeMessages->sendMessage($msg)->data['duplicate']===true,'CRM local replay failed.');
    $reject(fn()=>$nativeMessages->sendMessage(new SendMessageCommand($org,(string)$caseId,'EMAIL','Different','crm-'.$token)));
    $recon=['deal_id'=>$caseId,'provider'=>'aida','kind'=>'message','operation_key'=>'crm-'.$token];
    $check($gateway->invoke($manager,'sales.crm.reconciliation.read',$recon,'trusted_read','','crm-read')['state']==='local_verified','CRM native reconciliation failed.');
    $check($gateway->invoke($manager,'sales.crm.reconciliation.read',[...$recon,'provider'=>'unconfigured'],'trusted_read','','crm-read')['state']==='unsupported_provider','Unsupported CRM provider simulated success.');
    // Positive real Property reference/legacy projection fixture; Sales must not own or mutate its inventory.
    $asset='asset-'.$token;$inventory='inv-'.$token;
    $type=(int)$db->query('SELECT MIN(id) FROM tn_property_types')->fetchColumn();$location=(int)$db->query('SELECT MIN(id) FROM tn_locations')->fetchColumn();
    $check($type>0&&$location>0,'Property fixture vocabulary missing.');
    $db->prepare('INSERT INTO tn_properties(organization_id,public_id,slug,title,deal_type,type_id,status,location_id) VALUES (?,?,?,?,"sale",?,"draft",?)')->execute([$org,'CI-'.$token,'ci-'.$token,'CI property',$type,$location]);
    $propertyId=(int)$db->lastInsertId();
    $db->prepare('INSERT INTO tn_property_assets(organization_id,asset_id,kind,type_code,lifecycle,legacy_property_id) VALUES (?,?,"unit","apartment","existing",?)')->execute([$org,$asset,$propertyId]);
    $db->prepare('INSERT INTO tn_property_inventory_items(organization_id,inventory_id,asset_id,transaction_type,status,price_amount,price_currency) VALUES (?,?,?,"sale","available",1000,"USD")')->execute([$org,$inventory,$asset]);
    $match=['deal_id'=>$caseId,'property_id'=>$propertyId,'match_status'=>'suggested','score'=>90,'note'=>'CI match'];
    $matchReceipt=$invoke('sales.property_match.record',$match,'match-create');
    $check($matchReceipt['status']==='suggested'&&$invoke('sales.property_match.record',$match,'match-create')['replayed'],'Property match creation/replay failed.');
    $matchSent=['deal_id'=>$caseId,'property_id'=>$propertyId,'expected_status'=>'suggested','match_status'=>'sent'];
    $check($verifyActivity('sales.governed.property_match.record',$match,'match-create')==='sales:property_match:'.$matchReceipt['record_id'].':match-create','Independent Property match proof missing.');
    $check($invoke('sales.property_match.record',$matchSent,'match-sent')['status']==='sent','Property match transition failed.');
    $check($verifyActivity('sales.governed.property_match.record',$matchSent,'match-sent')==='sales:property_match:'.$matchReceipt['record_id'].':match-sent','Independent Property transition proof missing.');
    $reject(fn()=>$verifyActivity('sales.governed.property_match.record',$match,'match-create'));
    $reject(fn()=>$invoke('sales.property_match.record',[...$matchSent,'match_status'=>'rejected'],'match-stale'));
    $propertyQuote=['deal_id'=>$caseId,'title'=>'Property quote','currency'=>'USD','valid_until'=>'2035-01-01T00:00:00Z','lines'=>[['kind'=>'property','title'=>'Property','quantity'=>1,'unit_price'=>'1000.00','inventory_id'=>$inventory]]];
    $pq=$invoke('sales.offer.create',$propertyQuote,'property-quote')['offer'];
    $pidentity=['deal_id'=>$caseId,'offer_id'=>$pq['offer_id'],'expected_revision'=>1,'target_status'=>'presented','evidence_reference'=>'property-presented'];
    $check($invoke('sales.offer.transition',$pidentity,'property-presented')['offer']['status']==='presented','Property offer failed.');
    $db->prepare('UPDATE tn_property_inventory_items SET price_amount=1100 WHERE organization_id=? AND inventory_id=?')->execute([$org,$inventory]);
    $reject(fn()=>$invoke('sales.offer.transition',[...$pidentity,'expected_revision'=>2,'target_status'=>'accepted'],'property-drift'));
    $check((new MysqlPropertyReferencePort($db))->getInventorySnapshot($org,$inventory)['status']==='available','Sales wrote Property inventory.');
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
    // S4: native intake and commercial negotiation through the same tenant capability boundary.
    $operatorForm=['full_name'=>'Operator enquiry '.$token,'email'=>'operator-'.$token.'@example.test'];
    $operator=$invoke('sales.lead.intake',$operatorForm,'operator-intake');
    $check($operator['deal_id']>0&&!$operator['replayed'],'Operator intake missing native case.');
    $check($invoke('sales.lead.intake',$operatorForm,'operator-intake')['replayed'],'Operator intake replay missing.');
    $reject(fn()=>$invoke('sales.lead.intake',[...$operatorForm,'full_name'=>'Different'],'operator-intake'));
    $reject(fn()=>$invoke('sales.lead.intake',$operatorForm,'ai-intake','ai'));
    $product=bin2hex(random_bytes(16));$variant=bin2hex(random_bytes(16));
    $db->prepare("INSERT INTO pm_products (organization_id,product_id,product_type,status,name) VALUES (?,?,'service','active','CI catalog service')")->execute([$org,$product]);
    $db->prepare("INSERT INTO pm_variants (organization_id,variant_id,product_id,sku,uom,track_inventory,status) VALUES (?,?,?,?,?,0,'active')")->execute([$org,$variant,$product,'SKU-'.$token,'pcs']);
    $offerTerms=['title'=>'Native CI quote','currency'=>'USD','valid_until'=>'2035-01-01T00:00:00Z','lines'=>[
        ['kind'=>'service','title'=>'Work','quantity'=>1,'unit_price'=>'500.01'],
        ['kind'=>'product','title'=>'Catalog line','quantity'=>2,'unit_price'=>'249.99','product_id'=>$product,'variant_id'=>$variant],
    ]];
    $quote=['deal_id'=>$wonCase,...$offerTerms];
    $readFinance=fn()=>$gateway->invoke($manager,'finance.sales.commercial_evidence.read',['deal_id'=>$wonCase],'trusted_read','','finance-evidence');
    $check(!$readFinance()['found'],'Draft is not Finance evidence.');
    $reject(fn()=>$invoke('sales.offer.create',$quote,'ai-quote','ai'));
    $reject(fn()=>$invoke('sales.offer.create',[...$quote,'lines'=>[[...$offerTerms['lines'][1],'variant_id'=>'missing']]],'missing-variant'));
    $created=$invoke('sales.offer.create',$quote,'offer-create');$offerId=$created['offer']['offer_id'];
    $check($created['offer']['total']==='999.99'&&$created['offer']['revision']===1&&!$created['replayed'],'Native quote calculation mismatch.');
    $check($invoke('sales.offer.create',$quote,'offer-create')['replayed'],'Quote replay missing.');
    $reject(fn()=>$invoke('sales.offer.create',[...$quote,'title'=>'Changed'],'offer-create'));
    $identity=['deal_id'=>$wonCase,'offer_id'=>$offerId,'expected_revision'=>1];
    $reject(fn()=>$invoke('sales.offer.transition',[...$identity,'target_status'=>'accepted','evidence_reference'=>'customer-1'],'skip-presentation'));
    $present=$invoke('sales.offer.transition',[...$identity,'target_status'=>'presented','evidence_reference'=>'operator-email-1'],'present');
    $note=$invoke('sales.negotiation.record',[...$identity,'expected_revision'=>2,'kind'=>'objection','note'=>'Customer requested alternative'],'negotiation');
    $check($note['offer']['revision']===3&&$note['offer']['terms_version']===1,'Negotiation changed immutable terms.');
    $counter=$invoke('sales.offer.revise',[...$identity,'expected_revision'=>3,...$offerTerms,'direction'=>'customer','evidence_reference'=>'customer-counter-1'],'counter');
    $check($counter['offer']['terms_version']===2&&$counter['offer']['status']==='draft','Counteroffer not recorded.');
    $reject(fn()=>$invoke('sales.offer.transition',[...$identity,'expected_revision'=>4,'target_status'=>'presented','evidence_reference'=>'email-2'],'customer-draft'));
    $revised=$invoke('sales.offer.revise',[...$identity,'expected_revision'=>4,...$offerTerms,'direction'=>'sales'],'adopt-counter');
    $invoke('sales.offer.transition',[...$identity,'expected_revision'=>5,'target_status'=>'presented','evidence_reference'=>'operator-email-2'],'present-adopted');
    // Actual native Platform Notification/outbox, without sending real mail.
    $doc=$gateway->invoke($manager,'sales.offer.document.render',['offer_id'=>$offerId],'trusted_read','','doc-read');
    $check(str_contains($doc['html'],'999.99 USD')&&hash('sha256',$doc['text'])===$doc['document_hash']&&is_string($doc['recipient_hash']),'Offer document or recipient evidence invalid.');
    $deliveryParams=['expected_revision'=>6,'terms_hash'=>$doc['terms_hash'],'document_hash'=>$doc['document_hash'],'recipient_hash'=>$doc['recipient_hash']];
    $reject(fn()=>$gateway->invoke($manager,'sales.offer.delivery.queue',['target_type'=>'offer','target_id'=>$offerId,'parameters'=>$deliveryParams],'human_api','direct-delivery','trace'));
    $deliveryAction=new \Kernel\Action\Action(bin2hex(random_bytes(16)),$org,'sales.offer.delivery.queue','offer',$offerId,$deliveryParams,'USER',(string)$userId,'APPROVAL_REQUIRED','HIGH','offer-delivery',new DateTimeImmutable(),\Kernel\Action\ActionStatus::Running,'offer-trace');
    $deliveryHandler=new \Domains\Sales\Automation\Action\QueueSalesOfferDeliveryHandler($offerDelivery);
    $queued=$deliveryHandler->execute($deliveryAction);
    $check($queued->successful&&$queued->data['state']==='queued'&&$queued->data['recipient_delivery_verified']===false,'Offer transport queue evidence invalid.');
    $check($deliveryHandler->execute($deliveryAction)->data['notification_id']===$queued->data['notification_id'],'Offer delivery duplicated.');
    $status=$gateway->invoke($manager,'sales.offer.delivery.status',['offer_id'=>$offerId,'terms_version'=>3],'trusted_read','','status-read');
    $check($status['state']==='queued'&&$status['notification_id']===$queued->data['notification_id'],'Native delivery status mismatch.');
    $db->prepare('UPDATE tn_integration_outbox SET status="sent",sent_at=NOW() WHERE dedupe_key=?')->execute(['notification:'.$queued->data['notification_id']]);
    $status=$gateway->invoke($manager,'sales.offer.delivery.status',['offer_id'=>$offerId,'terms_version'=>3],'trusted_read','','status-read');
    $check($status['state']==='handed_off'&&$status['recipient_delivery_verified']===false,'Transport handoff falsely marked recipient delivery.');
    $accept=[...$identity,'expected_revision'=>6,'target_status'=>'accepted','evidence_reference'=>'customer-accept-1'];
    $accepted=$invoke('sales.offer.transition',$accept,'accept-offer');
    $check($accepted['offer']['status']==='accepted'&&$accepted['offer']['revision']===7&&$invoke('sales.offer.transition',$accept,'accept-offer')['replayed'],'Acceptance replay failed.');
    $evidence=$readFinance();$check($evidence['found']&&$evidence['evidence']['total']==='999.99'&&$evidence['evidence']['settled']===false,'Finance evidence mismatch.');
    $check($snapshot($wonCase)['status']!=='closed','Offer acceptance implicitly closed Deal.');
    $history=$gateway->invoke($manager,'sales.offer.read',['offer_id'=>$offerId],'trusted_read','','offer-read');
    $check(count($history['history'])===7&&count($history['terms_history'])===3,'Immutable negotiation history missing.');
    $check(count($gateway->invoke($manager,'sales.offer.list',['deal_id'=>$wonCase],'trusted_read','','offer-list')['items'])===1,'Offer list mismatch.');
    $check(!$gateway->invoke($actor('foreign-'.$token),'sales.offer.read',['offer_id'=>$offerId],'trusted_read','','foreign-read')['found'],'Cross-tenant quote leaked.');
    $reject(fn()=>$invoke('sales.offer.revise',[...$identity,'expected_revision'=>7,...$offerTerms,'direction'=>'sales'],'immutable-accepted'));
    $reject(fn()=>$invoke('sales.deal.commercial.set',['deal_id'=>$wonCase,'expected_revision'=>$snapshot($wonCase)['revision'],'deal_value'=>'1.00','currency'=>'USD'],'accepted-terms-lock'));
    $q->execute([$org,$offerId,'sales.offer.created']);$check((int)$q->fetchColumn()===1,'Offer event/outbox duplicated.');
    $current=$snapshot($wonCase);
    $pdef=$pipelines->getPipeline($org,$current['pipeline_id']);
    $queue=[[$current['stage_id'],[]]];$visited=[];$route=null;
    while($queue!==[]){[$from,$path]=array_shift($queue);if(isset($visited[$from]))continue;$visited[$from]=true;if($from===$won->id){$route=$path;break;}foreach($pdef->stages as $stage){if($stage->isLost||isset($visited[$stage->id]))continue;$transition=$pipelines->getTransition($org,$pdef->id,$from,$stage->id);if($transition!==null&&$transition->conditions===[]&&!$transition->requiresApproval)$queue[]=[$stage->id,[...$path,$stage->id]];}}
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
    // Historical analytics must use the immutable event snapshot, not current deal status.
    $history=new \Domains\Sales\Infrastructure\ReadModel\MySql\MysqlSalesCommercialAnalyticsReadModel($db);
    $dateQuery=$db->prepare("SELECT DATE(MIN(occurred_at)) first_day,DATE_ADD(DATE(MAX(occurred_at)),INTERVAL 1 DAY) end_day FROM cos_events WHERE organization_id=? AND aggregate_type='deal' AND aggregate_id IN (?,?) AND type IN ('sales.deal.won','sales.deal.lost')");
    $dateQuery->execute([$org,(string)$caseId,(string)$wonCase]);$period=$dateQuery->fetch(PDO::FETCH_ASSOC);
    $wonFacts=$history->outcomes($org,$userId,$pdef->id,$period['first_day'],$period['end_day']);
    $lostFacts=$history->outcomes($org,$targetOwner,$pdef->id,$period['first_day'],$period['end_day']);
    $check($wonFacts['won_count']===1&&$wonFacts['lost_count']===0&&$wonFacts['win_rate_percent']==='100.00'&&$wonFacts['booked_by_currency']===[['currency'=>'USD','deal_count'=>1,'booked_value'=>'999.99']],'WON historical evidence/amount incorrect.');
    $check($lostFacts['won_count']===0&&$lostFacts['lost_count']===1&&$lostFacts['win_rate_percent']==='0.00'&&$lostFacts['booked_by_currency']===[],'LOST historical owner scope incorrect.');
    $leadDate=$db->prepare('SELECT DATE(created_at) FROM tn_leads WHERE organization_id=? AND id=?');
    $leadDate->execute([$org,$leadId]);$cohortDay=(string)$leadDate->fetchColumn();
    $cohortEnd=(new DateTimeImmutable($cohortDay))->modify('+1 day')->format('Y-m-d');
    $nativeCohort=$history->conversion($org,$userId,null,$cohortDay,$cohortEnd);
    $check($nativeCohort['event_confirmed_count']>=1&&$nativeCohort['linked_count']>=$nativeCohort['event_confirmed_count'],'Native conversion event not reflected in cohort.');
    $empty=$history->outcomes('foreign-'.$token,$userId,$pdef->id,$period['first_day'],$period['end_day']);
    $check($empty['won_count']===0&&$empty['lost_count']===0&&$empty['win_rate_percent']===null,'Cross-tenant outcome isolation or empty-rate semantics failed.');
    $teamInput=['operation'=>'create','name'=>'CI Sales team','code'=>'CI_'.strtoupper($token)];
    $reject(fn()=>$invoke('sales.team.manage',$teamInput,'no-permission'));
    $db->prepare("INSERT INTO sales_user_capabilities(organization_id,user_id,capability,status,granted_by,created_at,updated_at) VALUES (?,?,'sales.admin.teams.manage','ACTIVE',?,NOW(6),NOW(6))")->execute([$org,$userId,(string)$userId]);
    $newTeam=$invoke('sales.team.manage',$teamInput,'new-team');
    $newTeamId=$newTeam['team']['team_id'];
    $check($newTeam['team']['configuration_version']===1 && !$newTeam['replayed'],'Team creation receipt invalid.');
    $check($invoke('sales.team.manage',$teamInput,'new-team')['replayed'],'Team create replay failed.');
    $member=['operation'=>'member_set','team_id'=>$newTeamId,'configuration_version'=>1,'user_id'=>$targetOwner,'role'=>'MEMBER',
        'member_status'=>'ACTIVE','assignment_enabled'=>true,'approval_enabled'=>false,'assignment_weight'=>3];
    $memberResult=$invoke('sales.team.manage',$member,'team-member');
    $check($memberResult['team']['configuration_version']===2 && count($memberResult['team']['members'])===1 && !isset($memberResult['team']['members'][0]['email']),'Team member or redaction failed.');
    $check($invoke('sales.team.manage',$member,'team-member')['replayed'],'Team member replay failed.');
    $reject(fn()=>$invoke('sales.team.manage',[...$member,'assignment_weight'=>4],'stale-team'));
    $reject(fn()=>$invoke('sales.team.manage',[...$member,'assignment_weight'=>4],'team-member'));
    $disable=['operation'=>'update','team_id'=>$newTeamId,'configuration_version'=>2,'name'=>'CI Sales team','status'=>'DISABLED'];
    $check($invoke('sales.team.manage',$disable,'disable')['team']['status']==='DISABLED','Disable team failed.');
    $reject(fn()=>$invoke('sales.team.manage',[...$member,'configuration_version'=>3],'disabled-member'));
    $teamRead=$gateway->invoke($manager,'sales.team.read',['team_id'=>$newTeamId],'trusted_read','','trace-team');
    $check($teamRead['found'] && $teamRead['team']['configuration_version']===3,'Team readback failed.');
    // R2: actual public intake, weighted assignment, replay and revoked eligibility.
    $defaultQ=$db->prepare('SELECT id FROM sales_teams WHERE organization_id=? AND code="DEFAULT"');
    $defaultQ->execute([$org]);$defaultTeam=$defaultQ->fetchColumn();
    $check(is_string($defaultTeam),'Default Sales team unavailable.');
    $db->prepare('UPDATE sales_teams SET status="ACTIVE",assignment_mode="ROUND_ROBIN",configuration_version=configuration_version+1 WHERE organization_id=? AND id=?')->execute([$org,$defaultTeam]);
    $db->prepare('UPDATE sales_team_members SET assignment_enabled=0 WHERE organization_id=? AND team_id=?')->execute([$org,$defaultTeam]);
    foreach([[$userId,2],[$targetOwner,1]] as [$memberId,$weight]){
        $db->prepare('INSERT INTO sales_team_members(organization_id,team_id,user_id,role,status,assignment_enabled,approval_enabled,assignment_weight,created_at,updated_at) VALUES (?,?,?,"MEMBER","ACTIVE",1,0,?,NOW(6),NOW(6)) ON DUPLICATE KEY UPDATE status="ACTIVE",assignment_enabled=1,assignment_weight=VALUES(assignment_weight)')->execute([$org,$defaultTeam,$memberId,$weight]);
        $db->prepare('INSERT INTO sales_user_capabilities(organization_id,user_id,capability,status,granted_by,created_at,updated_at) VALUES (?,?,"sales.workspace.use","ACTIVE",?,NOW(6),NOW(6)) ON DUPLICATE KEY UPDATE status="ACTIVE"')->execute([$org,$memberId,(string)$userId]);
    }
    $public=$factory->forOrganization($org);$owners=[];
    for($i=0;$i<3;$i++){
        $input=['full_name'=>'Round robin fixture','email'=>'rr-'.$token.'-'.$i.'@example.test','submission_id'=>bin2hex(random_bytes(16)),'assigned_user_id'=>999999];
        $out=$public->receivePublicLead($input,'r2-ci');$check($out->ok,'Round robin intake rejected.');
        $lead=$read->lead($org,(int)$out->data['lead_id']);$ownerId=(int)$lead['assigned_user_id'];$owners[]=$ownerId;
        $linked=$read->deal($org,(int)$lead['client_case_id']);
        $check($linked!==null&&(int)$linked['assigned_user_id']===$ownerId,'Lead and case owner diverged.');
        $check($public->receivePublicLead($input,'r2-ci')->data['lead_id']===$out->data['lead_id'],'Round robin replay created a lead.');
    }
    $check($owners===[$userId,$userId,$targetOwner],'Weighted round robin sequence or replay incorrect.');
    $db->prepare('UPDATE sales_team_members SET assignment_enabled=0 WHERE organization_id=? AND team_id=? AND user_id=?')->execute([$org,$defaultTeam,$userId]);
    $next=$public->receivePublicLead(['full_name'=>'Round robin eligibility','email'=>'rr-revoked-'.$token.'@example.test','submission_id'=>bin2hex(random_bytes(16))],'r2-ci');
    $check($next->ok&&(int)$read->lead($org,(int)$next->data['lead_id'])['assigned_user_id']===$targetOwner,'Disabled member received a lead.');
    $db->prepare('UPDATE sales_teams SET assignment_mode="MANUAL" WHERE organization_id=? AND id=?')->execute([$org,$defaultTeam]);
    $manual=$public->receivePublicLead(['full_name'=>'Manual fixture','email'=>'rr-manual-'.$token.'@example.test','submission_id'=>bin2hex(random_bytes(16))],'r2-ci');
    $check($manual->ok&&$read->lead($org,(int)$manual->data['lead_id'])['assigned_user_id']===null,'Manual mode unexpectedly assigned a lead.');
    echo "Sales R2 weighted public lead assignment, linked Deal, replay and eligibility PASS.\n";
    echo "Sales S1–S4 native intake, lifecycle, activities, Property/Product quote, approved-handler notification handoff, CRM evidence and WON/LOST PASS.\n";
} finally {
    if ($db->inTransaction()) $db->rollBack();
}
