<?php
declare(strict_types=1);

/** Native MySQL canonical Marketing Goal/Action acceptance. Meta transport, identity, pre-approved Finance holds and Plan Approval evidence are fixtures; no network. */
$root=dirname(__DIR__,2);
require (string)(getenv('COS_TEST_AUTOLOAD')?:$root.'/symfony/vendor/autoload.php');
spl_autoload_register(static function(string $class)use($root):void{
    if(!str_starts_with($class,'App\\'))return;
    $path=$root.'/symfony/src/'.str_replace('\\','/',substr($class,4)).'.php';
    if(is_file($path))require $path;
});

use App\Persistence\Federation\FederationCanonicalWorkflowBridge;
use App\Persistence\Federation\FederationCapabilityBindingResolver;
use App\Persistence\Federation\FederationGoalStore;
use App\Persistence\Federation\FederationPlanApprovalEvidenceReader;
use App\Persistence\Federation\FederationTrustedOutcomeEvidenceResolver;
use App\Persistence\Marketing\MarketingAnalystPlanProposalService;
use App\Persistence\Marketing\MarketingAnalystRunOutcomeProvider;
use App\Infrastructure\Marketing\HermesAnalystReadinessService;
use App\Persistence\Workflow\DoctrineWorkflowActionClaimAdmission;
use App\Persistence\Workflow\DoctrineWorkflowActionReceiptVerifier;
use App\Persistence\Workflow\DoctrineWorkflowActionWorkerAdmission;
use App\Persistence\Workflow\DoctrineWorkflowExecutionCoordinator;
use App\Persistence\Workflow\DoctrineWorkflowExecutionStore;
use App\Persistence\Workflow\FailClosedWorkflowNonActionReceiptEvidence;
use App\Persistence\Workflow\FederationWorkflowPlanApprovalEvidence;
use App\Persistence\Workflow\WorkflowPredecessorReceiptVerifier;
use Doctrine\DBAL\DriverManager;
use Infrastructure\Platform\Persistence\MySql\Action\MysqlActionRepository;
use Infrastructure\Platform\Persistence\MySql\Approval\MysqlApprovalRepository;
use Infrastructure\Platform\Persistence\MySql\Policy\MysqlPolicyEvaluationRepository;
use Infrastructure\Platform\Persistence\MySql\Policy\MysqlPolicyRepository;
use Kernel\Action\ActionStatus;
use Kernel\Action\Service\ActionExecutor;
use Kernel\Action\Service\ActionService;
use Kernel\Action\Service\ModuleActionExecutionGate;
use Kernel\Approval\ApprovalStatus;
use Kernel\Identity\Model\OrganizationRole;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Module\CanonicalCapabilityCatalog;
use Kernel\Module\Contract\ModuleStateRepositoryInterface;
use Kernel\Module\DomainModuleRegistry;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleContributions;
use Kernel\Module\ModuleDefinition;
use Kernel\Module\ModuleManifest;
use Kernel\Policy\Service\ActionPolicyService;
use Kernel\Policy\Service\PolicyContextBuilder;
use Kernel\Policy\Service\PolicyEngine;
use Kernel\Rule\Service\ConditionEvaluator;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Shared\Domain\UserId;
use Kernel\Tenant\Model\Permission;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Kernel\Transaction\Contract\TransactionManagerInterface;
use Platform\Orchestration\Goal\GoalOutcomeEvaluator;
use Platform\Orchestration\Goal\GoalPlanValidator;
use Platform\Workflow\Service\WorkflowStepScheduler;

$host=(string)(getenv('DB_HOST')?:'mysql');
$port=(int)(getenv('DB_PORT')?:3306);
$name=(string)(getenv('DB_DATABASE')?:'cos');
$user=(string)(getenv('DB_USERNAME')?:'cos');
$password=(string)(getenv('DB_PASSWORD')?:'');
$pdo=new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4",$user,$password,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db=DriverManager::getConnection(['driver'=>'pdo_mysql','host'=>$host,'port'=>$port,
    'dbname'=>$name,'user'=>$user,'password'=>$password,'charset'=>'utf8mb4']);
$token=bin2hex(random_bytes(7));$org='mkt-paid-'.$token;
$owner='42';$reviewer='43';
$runId='run-mkt-'.$token;$planApproval=bin2hex(random_bytes(16));
$assert=static function(bool $ok,string $why):void{
    if(!$ok)throw new RuntimeException('Marketing paid Goal E2E: '.$why);
};
$deny=static function(callable $fn,string $why):void{
    try{$fn();}catch(Throwable){return;}
    throw new RuntimeException('Unsafe Marketing E2E admitted: '.$why);
};
$actor=new TenantContext(UserId::fromString($owner),
    OrganizationId::fromString($org),OrganizationRole::fromString('manager'),
    [Permission::fromString(TenantPermissions::ACCESS),Permission::fromString(TenantPermissions::MANAGE)]);
$oldRollout=getenv('COS_FEDERATION_CANONICAL_ORGANIZATION');
putenv('COS_FEDERATION_CANONICAL_ORGANIZATION='.$org);

try {
    $pdo->prepare('INSERT INTO cos_organizations (id,name,status) VALUES (:id,:name,"ACTIVE")')
        ->execute(['id'=>$org,'name'=>'Marketing approved full canonical E2E']);
    $users=[];
    foreach(['owner','reviewer'] as $label){
        $pdo->prepare('INSERT INTO tn_users (organization_id,email,password_hash,full_name,role,status) VALUES (?,?,?,?, "manager","active")')->execute([$org,$label.'-'.$token.'@example.invalid','test-only',$label]);
        $uid=(string)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO cos_organization_memberships (organization_id,user_id,role,status) VALUES (?,?,"manager","ACTIVE")')->execute([$org,(int)$uid]);
        $users[]=$uid;
    }
    [$owner,$reviewer]=$users;
    $actor=new TenantContext(UserId::fromString($owner),OrganizationId::fromString($org),
        OrganizationRole::fromString('manager'),[Permission::fromString(TenantPermissions::ACCESS),Permission::fromString(TenantPermissions::MANAGE)]);
    $transactions=new class($pdo) implements TransactionManagerInterface {
        public function __construct(private PDO $pdo){}
        public function transactional(callable $op):mixed{
            if($this->pdo->inTransaction())return $op();
            $this->pdo->beginTransaction();
            try{$result=$op();$this->pdo->commit();return $result;}
            catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
        }
        public function isActive():bool{return $this->pdo->inTransaction();}
        public function afterCommit(callable $fn):void{
            if($this->pdo->inTransaction())throw new RuntimeException('Uncommitted test-local afterCommit');
            $fn();
        }
    };
    $manifest=require $root.'/app/Domains/Marketing/module.php';
    $catalog=new ModuleCatalog([ModuleDefinition::fromArray($manifest),ModuleDefinition::fromArray(require $root.'/app/Domains/Finance/module.php'),
        new ModuleDefinition(new ModuleManifest('federation','Federation','1.0.0'),new ModuleContributions()),
        new ModuleDefinition(new ModuleManifest('documents','Documents','1.0.0'),new ModuleContributions())]);
    $states=new class implements ModuleStateRepositoryInterface {
        public function enabledOverride(string $org,string $moduleId):?bool{return true;}
        public function setEnabled(string $org,string $moduleId,bool $value):void{}
    };
    $modules=new ActiveModuleResolver($catalog,$states);
    $capabilities=new CanonicalCapabilityCatalog($catalog);
    $planReader=new FederationPlanApprovalEvidenceReader($db);
    $planEvidence=new FederationWorkflowPlanApprovalEvidence($db,$planReader);
    $receipts=new DoctrineWorkflowActionReceiptVerifier($db,$planEvidence);
    $admission=new DoctrineWorkflowActionClaimAdmission($db,$planEvidence,$capabilities,$modules);
    $store=new DoctrineWorkflowExecutionStore($db,$receipts,$admission);
    $worker=new DoctrineWorkflowActionWorkerAdmission($db,$planEvidence,$capabilities,$modules,
        new WorkflowPredecessorReceiptVerifier($receipts,new FailClosedWorkflowNonActionReceiptEvidence()));
    $campaigns=new \Domains\Marketing\Infrastructure\Persistence\MySql\MysqlMarketingCampaignRepository($pdo);
    $planning=new \Domains\Marketing\Infrastructure\Persistence\MySql\MysqlMarketingPlanningRepository($pdo);
    $campaignService=new \Domains\Marketing\Application\Service\MarketingCampaignService($campaigns,new \Domains\Marketing\Domain\MarketingDraftPolicy());
    $planningPolicy=new \Domains\Marketing\Domain\MarketingPlanningPolicy();
    $product=new class implements \Domains\Marketing\Application\Contract\MarketingProductDraftReadPort {
        public function inspect(string $organizationId,string $productId,?string $variantId,string $currency):?array{throw new DomainException('Product not required for this fixture.');}
        public function search(string $organizationId,string $query,int $limit,int $offset):array{throw new DomainException('Product not required.');}
        public function stock(string $organizationId,string $productId,string $variantId,string $locationId):?array{throw new DomainException('Product not required.');}
    };
    $contexts=new \Domains\Marketing\Application\Service\MarketingAnalystContextService($campaignService,
        new \Domains\Marketing\Application\Service\MarketingPlanningService($planning,$planningPolicy),
        new \Domains\Marketing\Application\Service\MarketingProductDraftCheckService($product));
    $runs=new \Domains\Marketing\Infrastructure\Persistence\MySql\MysqlMarketingAnalystRunStore($pdo);
    $budget=new \Infrastructure\Llm\MysqlLlmGovernanceRepository($pdo);
    $pdo->prepare("INSERT INTO cos_llm_budgets (organization_id,currency,monthly_limit) VALUES (?,'USD',0.2500)")->execute([$org]);
    $profiles=new \Domains\Marketing\Infrastructure\Hermes\HermesAnalystConfigurationRegistry(json_encode([$org=>[
        'enabled'=>true,'profile_ref'=>'mkt-e2e','profile_url'=>'https://hermes.example.com/p/mkt-e2e',
        'credential_ref'=>'env://TEST_HERMES_SECRET','daily_run_limit'=>2,'cost_control_ref'=>'fixture-provider-cap',
        'isolation_evidence_ref'=>'fixture-isolation','accepted_until'=>gmdate('Y-m-d\\TH:i:s\\Z',time()+86400),'max_cost_usd'=>'0.2500'
    ]],JSON_THROW_ON_ERROR));
    $vault=new class implements \Platform\Integration\Contract\CredentialVaultInterface{
        public function resolve(\Platform\Integration\Model\Credential $credential):array{return ['access_token'=>'fixture-token'];}
    };
    $transport=new class($budget,$org) implements \Domains\Marketing\Infrastructure\Hermes\HermesAnalystTransport {
        public int $posts=0;
        public int $reads=0;
        public function __construct(private \Infrastructure\Llm\MysqlLlmGovernanceRepository $budget,private string $org){}
        public function get(string $url,string $token):array{
            if($url!=='https://hermes.example.com/p/mkt-e2e/v1/toolsets'||$token!=='fixture-token')throw new RuntimeException('Unexpected fixture profile.');
            ++$this->reads;return [];
        }
        public function post(string $url,string $token,string $key,array $payload):array{
            if($url!=='https://hermes.example.com/p/mkt-e2e/v1/chat/completions'||$token!=='fixture-token'
                ||!preg_match('/^cos-mkt-[a-f0-9]{64}$/D',$key)||abs($this->budget->monthlySpend($this->org,'USD')-0.25)>0.000001){
                throw new RuntimeException('External turn lacked authenticated profile or budget admission.');
            }
            ++$this->posts;
            $facts=json_decode($payload['messages'][1]['content'],true,32,JSON_THROW_ON_ERROR);
            if(str_contains($payload['messages'][1]['content'],'destination_url')||str_contains($payload['messages'][1]['content'],'fixture-token')){
                throw new RuntimeException('Unnecessary data sent to runtime.');
            }
            return ['id'=>'chatcmpl-marketing-e2e','choices'=>[['finish_reason'=>'stop','message'=>['role'=>'assistant',
                'content'=>json_encode(['context_hash'=>$facts['context_hash'],'recommendation'=>'Test a shorter headline',
                    'rationale'=>'Planning hypothesis without verified performance evidence','evidence_aliases'=>['creative']],JSON_THROW_ON_ERROR)]]]];
        }
    };
    $identityResolver=new class($org,$owner) implements \Kernel\Identity\Contract\IdentityResolverInterface {
        public bool $active=true;
        public function __construct(private string $org,private string $owner){}
        public function resolve(UserId $userId,OrganizationId $organizationId):?\Kernel\Identity\Model\AuthenticatedIdentity{
            if(!$this->active||$organizationId->value()!==$this->org||$userId->value()!==$this->owner)return null;
            return new \Kernel\Identity\Model\AuthenticatedIdentity($userId,$organizationId,OrganizationRole::fromString('manager'));
        }
    };
    $tenantFactory=new \Kernel\Tenant\Service\TenantContextFactory(new class implements \Kernel\Tenant\Contract\TenantPermissionResolverInterface{
        public function permissionsFor(\Kernel\Identity\Model\AuthenticatedIdentity $identity):array{
            return [Permission::fromString(TenantPermissions::ACCESS),Permission::fromString(TenantPermissions::MANAGE)];
        }
    });
    $factory=new \Domains\Marketing\Infrastructure\Hermes\HermesAnalystExecutionFactory($profiles,$contexts,$runs,$vault,$transport,$budget,$modules);
    $handler=new \Domains\Marketing\Automation\Action\MarketingAnalystRunHandler($factory,$worker,$identityResolver,$tenantFactory,$modules);
    $financeRepo=new \Domains\Finance\Infrastructure\Persistence\MySql\MysqlFinanceBudgetRepository($pdo);
    $financePolicy=new \Domains\Finance\Domain\BudgetControlPolicy();
    $financeRead=new \Domains\Finance\Application\Service\FinanceBudgetControlService($financeRepo,$financePolicy);
    $financeOps=new \Domains\Finance\Application\Service\ApprovedFinanceBudgetOperations($financeRepo,$financePolicy);
    $financeClaim=new \App\Infrastructure\Marketing\FinancePaidExposureClaimAdapter(
        new \Domains\Finance\Application\Service\ApprovedFinancePaidExposureService($financeRepo,$worker,$modules,$financePolicy));
    $accounts=new \Domains\Marketing\Infrastructure\Persistence\MySql\MysqlMarketingPaidReadRepository($pdo);
    $providerAccount='act_'.random_int(1000000000,9999999999);
    $account=$accounts->registerAccount($org,'env://META_FIXTURE_READ',['ad_account_id'=>$providerAccount,'currency'=>'USD',
        'timezone'=>'UTC','connection_status'=>'READ_ONLY','capability_scope'=>['ads_read']]);
    $campaign=$campaigns->createDraft($org,$owner,(new \Domains\Marketing\Domain\MarketingDraftPolicy())->validate([
        'name'=>'Paid fixture housing traffic','business_objective'=>'Housing listing traffic','brief'=>'Synthetic fixture only'
    ]),'paid-campaign-'.$token);
    $creative=$planning->createCreative($org,$campaign['id'],$owner,$planningPolicy->creative([
        'headline'=>'Synthetic housing listing','primary_text'=>'Fixture housing traffic test.','call_to_action'=>'Read more',
        'destination_url'=>'https://example.com/catalog','language'=>'uk'
    ]),'paid-creative-'.$token);
    $audience=$planning->saveAudience($org,$owner,null,null,$planningPolicy->audience([
        'name'=>'Retail audience','target_spec'=>['market_countries'=>['UA'],'audience_hypothesis'=>'General retail demand']
    ]),'paid-audience-'.$token);
    $adset=$planning->saveAdset($org,$campaign['id'],1,$owner,null,null,$planningPolicy->adset([
        'name'=>'Retail traffic','audience_id'=>$audience['id'],'audience_revision'=>1,
        'creatives'=>[['creative_id'=>$creative['id'],'revision'=>1]],'placements'=>['AUTOMATIC'],
        'optimization'=>'TRAFFIC','planned_daily_budget'=>'12.50','currency'=>'USD'
    ]),'paid-adset-'.$token);
    $fundPlans=new \Domains\Marketing\Application\Service\MarketingPaidLaunchPlanService(
        new \Domains\Marketing\Infrastructure\Persistence\MySql\MysqlMarketingPaidLaunchPlanStore($pdo),$campaignService,
        new \Domains\Marketing\Application\Service\MarketingPlanningService($planning,$planningPolicy),$accounts,
        new \App\Infrastructure\Marketing\FinanceFundingReadAdapter($financeRead,$modules),$modules);
    $today=gmdate('Y-m-d');$start=gmdate('Y-m-d',time()+86400);$end=gmdate('Y-m-d',time()+3*86400);
    $paidPlan=$fundPlans->create($actor,$campaign['id'],1,$adset['id'],1,$account['id'],1,$start,$end,'paid-plan-'.$token);
    $audiencePins=$fundPlans->comparisonPins($actor,$paidPlan['id']);
    $assert($audiencePins===['audience_id'=>$audience['id'],'audience_revision'=>1,'planned_countries'=>['UA'],'creative_hash'=>$creative['content_hash']],
        'Paid plan historical comparison pins did not bind native audience and creative.');
    $envelope=$financeRead->createDraft($actor,['name'=>'Synthetic paid exposure','source_domain'=>'marketing','source_ref'=>$paidPlan['id'],
        'currency'=>'USD','period_start'=>$today,'period_end'=>$end,'limit_minor'=>$paidPlan['lifetime_minor']],'paid-budget-'.$token);
    $active=$financeOps->activate($org,$envelope['id'],$reviewer,bin2hex(random_bytes(16)),'fixture-finance',1,'paid-budget-activate-'.$token);
    $reservation=$financeOps->reserve($org,$envelope['id'],$reviewer,bin2hex(random_bytes(16)),'fixture-finance',
        $paidPlan['lifetime_minor'],$active['revision'],'marketing',$paidPlan['finance_operation_key'],'Fixture approved reservation','paid-hold-'.$token);
    $metaProfiles=new \Domains\Marketing\Infrastructure\Meta\MetaPaidExecutionProfiles(json_encode([$org=>[$paidPlan['id']=>[
        'accepted_until'=>gmdate('Y-m-d\\TH:i:s\\Z',time()+86400),'account_id'=>$account['id'],'billing_cap_evidence_ref'=>'fixture-billing-cap',
        'creative_hash'=>$creative['content_hash'],'creative_id'=>'90004','credential_ref'=>'env://META_FIXTURE_WRITE',
        'exclusive_control_evidence_ref'=>'fixture-exclusive-control','special_category_review_ref'=>'fixture-housing-review',
        'special_ad_categories'=>['HOUSING'],'special_ad_category_country'=>['UA'],
        'plan_hash'=>$paidPlan['plan_hash'],'provider_account_id'=>$providerAccount
    ]]],JSON_THROW_ON_ERROR),true);
    $writeVault=new class implements \Platform\Integration\Contract\CredentialVaultInterface {
        public function resolve(\Platform\Integration\Model\Credential $credential):array{
            if($credential->kind!=='meta_ads_write'||$credential->secretReference!=='env://META_FIXTURE_WRITE')throw new DomainException('Wrong write credential.');
            return ['token'=>'fixture-write-only'];
        }
    };
    $metaTransport=new class($providerAccount,$financeRepo,$org,$reservation['id']) implements \Domains\Marketing\Infrastructure\Meta\MetaWriteTransport{
        public array $writes=[];
        private array $rows=[];
        public function __construct(private string $account,private \Domains\Finance\Infrastructure\Persistence\MySql\MysqlFinanceBudgetRepository $finance,
            private string $org,private string $reservation){
            $this->rows['90004']=['id'=>'90004','account_id'=>substr($account,4)];
        }
        public function request(string $method,string $path,array $fields,string $token):array{
            if($token!=='fixture-write-only')throw new RuntimeException('Wrong fixture write token.');
            if($method==='GET'){
                if(isset($this->rows[$path]))return $this->rows[$path];
                if(preg_match('~^(90001|90002)/(adsets|ads)$~D',$path,$m)){
                    $child=$m[2]==='adsets'?'90002':'90003';
                    return ['data'=>isset($this->rows[$child])?[['id'=>$child,'account_id'=>substr($this->account,4)]]:[]];
                }
                throw new RuntimeException('Unknown fixture object.');
            }
            $this->writes[]=[$path,$fields['status']??null];
            if(str_starts_with($path,$this->account.'/')){
                if(($fields['status']??null)!=='PAUSED')throw new RuntimeException('Fixture creation not paused.');
                $edge=substr($path,strlen($this->account)+1);
                $id=match($edge){'campaigns'=>'90001','adsets'=>'90002','ads'=>'90003',default=>throw new RuntimeException('Unknown create edge.')};
                if(isset($this->rows[$id]))throw new RuntimeException('Duplicate provider creation.');
                $row=['id'=>$id,'account_id'=>substr($this->account,4),'configured_status'=>'PAUSED','effective_status'=>'PAUSED',...$fields];
                if($edge==='campaigns'){
                    $row['special_ad_categories']=json_decode($fields['special_ad_categories'],true,16,JSON_THROW_ON_ERROR);
                    $row['special_ad_category_country']=json_decode($fields['special_ad_category_country']??'[]',true,16,JSON_THROW_ON_ERROR);
                    if($row['special_ad_categories']!==['HOUSING']||$row['special_ad_category_country']!==['UA'])
                        throw new RuntimeException('Housing category was not transmitted.');
                }
                if($edge==='adsets')$row['targeting']=json_decode($fields['targeting'],true,16,JSON_THROW_ON_ERROR);
                if($edge==='ads')$row['creative']=['id'=>json_decode($fields['creative'],true,16,JSON_THROW_ON_ERROR)['creative_id']];
                $this->rows[$id]=$row;return ['id'=>$id];
            }
            if(!isset($this->rows[$path])||!in_array($fields['status']??null,['PAUSED','ACTIVE'],true))throw new RuntimeException('Unexpected status mutation.');
            if($fields['status']==='ACTIVE'){
                if($this->finance->exposure($this->org,$this->reservation)===null)throw new RuntimeException('Activation without durable Finance claim.');
                if($path!=='90001'&&$this->rows['90001']['configured_status']!=='PAUSED')throw new RuntimeException('Child activated under active parent.');
                if($path==='90001'&&($this->rows['90002']['configured_status']!=='ACTIVE'||$this->rows['90003']['configured_status']!=='ACTIVE'))
                    throw new RuntimeException('Campaign activated before children.');
            }
            $this->rows[$path]['configured_status']=$fields['status'];
            return ['success'=>true];
        }
    };
    $journal=new \Domains\Marketing\Infrastructure\Persistence\MySql\MysqlMarketingPaidOperationJournal($pdo);
    $paidLifecycle=new \Domains\Marketing\Infrastructure\Meta\ApprovedMetaPaidLifecycle($fundPlans,$metaProfiles,
        new \Domains\Marketing\Infrastructure\Meta\MetaPaidWriteAdapter($writeVault,$metaTransport),$journal,$financeClaim);
    $paidHandler=new \Domains\Marketing\Automation\Action\MarketingPaidLifecycleHandler($paidLifecycle,$worker,$identityResolver,$tenantFactory,$modules);
    $domains=new DomainModuleRegistry([new \Domains\Marketing\Bootstrap\MarketingDomainModule($handler,$paidHandler)]);
    $bindings=new FederationCapabilityBindingResolver($capabilities,$domains,$modules);
    $gate=new ModuleActionExecutionGate($domains,$modules,null,$worker);
    $actionService=new ActionService(new MysqlActionRepository($pdo),new ActionExecutor([$handler,$paidHandler],$gate));
    $approvalRepo=new MysqlApprovalRepository($pdo);$policies=new MysqlPolicyRepository($pdo);$engine=new PolicyEngine(new ConditionEvaluator());
    $policyContexts=new PolicyContextBuilder($domains);
    $policyService=new ActionPolicyService($actionService,$policies,new MysqlPolicyEvaluationRepository($pdo),$approvalRepo,$engine,
        $transactions,null,$domains,$modules,$policyContexts);
    $outcome=new \App\Persistence\Marketing\MarketingPaidGoalOutcomeProvider($db,$fundPlans,$journal,$receipts);
    $goals=new FederationGoalStore($db,$bindings,new GoalPlanValidator($capabilities),new GoalOutcomeEvaluator(),
        new FederationTrustedOutcomeEvidenceResolver($modules,[$outcome]),$planReader);
    $scheduler=new WorkflowStepScheduler();
    $bridge=new FederationCanonicalWorkflowBridge($db,$store,$scheduler,$goals,$planEvidence,$receipts,
        new FailClosedWorkflowNonActionReceiptEvidence(),$planReader,$capabilities,$domains,$modules,$policies,$engine,$policyContexts,$policyService,
        new DoctrineWorkflowExecutionCoordinator($db,$store,$scheduler,$planEvidence,$receipts));
    $readAdapter=new class implements \Domains\Marketing\Application\Contract\PaidMediaProviderInterface{
        public function inspectAccount(string $organizationId,string $providerAccountId,string $credentialRef):array{throw new DomainException('Network disabled.');}
        public function hierarchy(string $organizationId,string $providerAccountId,string $credentialRef):array{throw new DomainException('Network disabled.');}
        public function insights(string $organizationId,string $providerAccountId,string $credentialRef,string $from,string $to,string $grain):array{throw new DomainException('Network disabled.');}
    };
    $paidRead=new \Domains\Marketing\Application\Service\MarketingPaidReadService($accounts,$readAdapter);
    $read=new \Domains\Marketing\Application\Service\MarketingPaidOperationsReadService($fundPlans,$journal,$metaProfiles,$paidRead);
    // Synthetic provider insight record; a report alone must not authorize financial closure.
    $accounts->importInsights($org,$account['id'],'campaign',$start,$end,[['campaign_id'=>'90001','date_start'=>$start,'date_stop'=>$end,'spend'=>'12.50']]);
    $snapshotId=$accounts->insights($org,$account['id'],'campaign',$start,$end)[0]['id'];
    $deny(fn()=>$read->settlementSource($actor,$paidPlan['id'],$snapshotId),'Terminal evidence accepted before paid lifecycle.');
    $planner=new \App\Persistence\Marketing\MarketingPaidGoalProposalService($db,$goals,$bridge,$fundPlans,$read);
    $assert($read->status($actor,$paidPlan['id'])['profile_configured']&&!$read->status($actor,$paidPlan['id'])['live_verified'],'Profile overclaims live verification.');
    $deny(fn()=>$planner->propose($actor,$paidPlan['id'],'activate','premature-activation-'.$token,$envelope['id'],$reservation['id']),'Activation before deployment.');
    foreach(['create_paused','activate','pause'] as $op){
        $cap='marketing.paid.meta.campaign.'.$op;
        $pdo->prepare('INSERT INTO cos_policies (id,organization_id,code,name,action_type,conditions,decision,priority,version,status)
            VALUES (?,?,?,?,?,?,"APPROVAL_REQUIRED",1,1,"ACTIVE")')
            ->execute([bin2hex(random_bytes(16)),$org,'paid-'.$op,'Independent paid approval',$cap,'[]']);
        $proposal=$planner->propose($actor,$paidPlan['id'],$op,'paid-goal-'.$op.'-'.$token,
            $op==='activate'?$envelope['id']:null,$op==='activate'?$reservation['id']:null);
        $goalId=$proposal['goal_id'];$planId=$proposal['plan_id'];$planApproval=bin2hex(random_bytes(16));$runId='run-paid-'.$op.'-'.$token;
        $before=count($metaTransport->writes);
        $deny(fn()=>$bridge->start($actor,$runId,$planId,$planApproval),'Unapproved paid plan started.');
        $db->executeStatement('UPDATE cos_federation_plans SET state=:state WHERE organization_id=:org AND plan_id=:plan',
            ['state'=>'approved','org'=>$org,'plan'=>$planId]);
        $storedPlan=(string)$db->fetchOne('SELECT plan_json FROM cos_federation_plans WHERE organization_id=:org AND plan_id=:plan',
            ['org'=>$org,'plan'=>$planId]);
        $db->insert('cos_actions',['id'=>$planApproval,'organization_id'=>$org,'type'=>'cos.federation.plan.approval',
            'target_type'=>'cos_federation_plan','target_id'=>$planId,'parameters'=>json_encode(['goal_id'=>$goalId,'plan_id'=>$planId,
                'specification_version'=>1,'plan_hash'=>hash('sha256',$storedPlan)],JSON_THROW_ON_ERROR),
            'source_type'=>'USER','source_id'=>$owner,'status'=>'COMPLETED','execution_mode'=>'APPROVAL_REQUIRED','risk_level'=>'HIGH',
            'idempotency_key'=>'plan:'.$op.':'.$token,'correlation_id'=>'paid-plan-'.$op.'-'.$token]);
        $db->insert('cos_policy_evaluations',['id'=>bin2hex(random_bytes(16)),'organization_id'=>$org,'action_id'=>$planApproval,
            'decision'=>'APPROVAL_REQUIRED','correlation_id'=>'paid-plan-'.$op.'-'.$token,'evaluated_at'=>gmdate('Y-m-d H:i:s.u')]);
        $db->insert('cos_approvals',['id'=>bin2hex(random_bytes(16)),'organization_id'=>$org,'action_id'=>$planApproval,'status'=>'APPROVED',
            'approver_type'=>'USER','approver_id'=>$reviewer,'requested_by_type'=>'USER','requested_by_id'=>$owner,'decided_by_type'=>'USER',
            'decided_by_id'=>$reviewer,'decided_at'=>gmdate('Y-m-d H:i:s.u'),'expires_at'=>(new DateTimeImmutable('+1 day'))->format('Y-m-d H:i:s.u')]);
        $assert($bridge->start($actor,$runId,$planId,$planApproval)['execution_authority']==='platform_workflow','Wrong execution authority.');
        $assert($bridge->advance($actor,$runId,$planApproval)['state']==='running','Paid workflow did not start.');
        $pending=$bridge->advance($actor,$runId,$planApproval);
        $assert($pending['state']==='awaiting_human_approval','Missing Action approval.');
        $actionId=$pending['action_id'];
        $assert($actionService->execute($org,$actionId,'premature-worker')===null&&count($metaTransport->writes)===$before,'Unapproved provider write.');
        $approvalId=(string)$db->fetchOne('SELECT id FROM cos_approvals WHERE organization_id=:org AND action_id=:action',
            ['org'=>$org,'action'=>$actionId]);
        $assert($approvalRepo->decide($org,$approvalId,ApprovalStatus::Approved,$reviewer,'Synthetic fixture accepted'),'Paid approval not persisted.');
        $actionService->queue($org,$actionId);
        $executed=$actionService->execute($org,$actionId,'paid-approved-worker');
        $assert($executed?->status===ActionStatus::Completed,'Paid canonical worker failed: '.$op);
        $assert($actionService->execute($org,$actionId,'replay-worker')===null,'Completed paid Action replayed.');
        $assert(count($metaTransport->writes)===$before+($op==='pause'?1:3),'Unexpected provider write count: '.$op);
        $assert($bridge->advance($actor,$runId,$planApproval)['state']==='step_completed','Native paid receipt not verified.');
        $assert($bridge->advance($actor,$runId,$planApproval)['state']==='completed','Paid Workflow not completed.');
        $evaluation=$goals->recordEvaluation($actor,'paid-eval-'.$op.'-'.$token,$runId);
        $assert(($evaluation['result']??null)==='satisfied'&&($evaluation['criteria'][0]['observed']??null)===1,'Paid Goal receipt not verified.');
    }
    $assert($financeRepo->exposure($org,$reservation['id'])!==null&&$financeRepo->envelope($org,$envelope['id'])['reserved_minor']===$paidPlan['lifetime_minor'],
        'Pause or activation released held exposure.');
    $deny(fn()=>$financeOps->release($org,$reservation['id'],$reviewer,bin2hex(random_bytes(16)),'fixture-release','Unsafe release after pause','unsafe-release-'.$token),
        'Claimed exposure released without settlement.');
    $assert($metaTransport->writes[count($metaTransport->writes)-1]===['90001','PAUSED'],'Campaign was not paused.');
    $native=$read->settlementSource($actor,$paidPlan['id'],$snapshotId);
    $assert($native['plan']['plan_hash']===$paidPlan['plan_hash']&&$native['terminal']['state']==='PAUSED_CONFIRMED'
        &&$native['terminal']['activation_action_id']===$financeRepo->exposure($org,$reservation['id'])['action_id']
        &&$native['observation']['source_snapshot_ref']===$snapshotId&&$native['observation']['reported_spend_decimal']==='12.500000',
        'Native Marketing settlement source readback differs from confirmed receipts.');
    $deny(fn()=>$read->settlementSource($actor,$paidPlan['id'],'mis-'.str_repeat('0',24)),'Unrelated insight accepted for settlement.');
    $performance=new \Domains\Marketing\Application\Service\MarketingAnalystPerformanceService($fundPlans,$read,$paidRead,$accounts);
    $audienceComparison=new \App\Application\Capability\MarketingAudienceOptimizationPreviewAdapter(
        new \Domains\Marketing\Application\Service\MarketingAudienceOptimizationService($fundPlans,
            new \Domains\Marketing\Application\Service\MarketingBudgetOptimizationService($fundPlans,$read,$performance)));
    $blocked=false;
    try{$audienceComparison->invoke($actor,['first_plan_id'=>$paidPlan['id'],'first_snapshot_ref'=>'mis-'.str_repeat('a',24),
        'second_plan_id'=>$paidPlan['id'],'second_snapshot_ref'=>'mis-'.str_repeat('b',24)],'fixture-audience-preview','fixture');}
    catch(DomainException){$blocked=true;}
    $assert($blocked,'Nonterminal/current-period audience comparison admitted.');
    // Register a new controlled creative hypothesis; neither arm has any paid operation.
    $experimentStore=new \Domains\Marketing\Infrastructure\Persistence\MySql\MysqlMarketingExperimentStore($pdo);
    $experiments=new \Domains\Marketing\Application\Service\MarketingExperimentService($experimentStore,$fundPlans,$journal,
        new \Domains\Marketing\Application\Service\MarketingBudgetOptimizationService($fundPlans,$read,$performance),$modules);
    $register=new \App\Application\Capability\MarketingExperimentRegisterAdapter($experiments);
    $getExperiment=new \App\Application\Capability\MarketingExperimentGetAdapter($experiments);
    $creativePreview=new \App\Application\Capability\MarketingCreativeOptimizationPreviewAdapter($experiments);
    $makeArm=static function(string $label,string $headline,string $primaryText,?array $armAudience=null)use($org,$owner,$token,$campaigns,$planning,$planningPolicy,$audience,
        $fundPlans,$actor,$account,$start,$end):array{
        if($armAudience!==null)$audience=$armAudience;
        $c=$campaigns->createDraft($org,$owner,(new \Domains\Marketing\Domain\MarketingDraftPolicy())->validate([
            'name'=>'Experiment '.$label,'business_objective'=>'Experimental traffic','brief'=>'Synthetic experimental design'
        ]),'experiment-campaign-'.$label.'-'.$token);
        $cr=$planning->createCreative($org,$c['id'],$owner,$planningPolicy->creative([
            'headline'=>$headline,'primary_text'=>$primaryText,'call_to_action'=>'Read more','destination_url'=>'https://example.com/catalog','language'=>'uk'
        ]),'experiment-creative-'.$label.'-'.$token);
        $as=$planning->saveAdset($org,$c['id'],1,$owner,null,null,$planningPolicy->adset([
            'name'=>'Experiment traffic','audience_id'=>$audience['id'],'audience_revision'=>1,'creatives'=>[['creative_id'=>$cr['id'],'revision'=>1]],
            'placements'=>['AUTOMATIC'],'optimization'=>'TRAFFIC','planned_daily_budget'=>'12.50','currency'=>'USD'
        ]),'experiment-adset-'.$label.'-'.$token);
        return $fundPlans->create($actor,$c['id'],1,$as['id'],1,$account['id'],1,$start,$end,'experiment-paid-plan-'.$label.'-'.$token);
    };
    $control=$makeArm('control','Control headline','Identical body text.');
    $candidate=$makeArm('candidate','Candidate headline','Identical body text.');
    $confounded=$makeArm('confounded','Third headline','Changed body text.');
    $experimentInput=['control_plan_id'=>$control['id'],'candidate_plan_id'=>$candidate['id'],'factor'=>'HEADLINE'];
    $experimentKey='experiment-register-'.$token;
    $writesBefore=count($metaTransport->writes);
    $experiment=$register->invoke($actor,$experimentInput,$experimentKey,'fixture');
    $assert($experiment['state']==='REGISTERED_PROTOCOL_ONLY'&&$experiment['source_pins_current']
        &&$experiment['protocol']['control']['plan_id']===$control['id']&&$experiment['protocol']['candidate']['plan_id']===$candidate['id']
        &&$experiment['protocol']['target_allocation_percent']===50&&!$experiment['randomization_verified']
        &&!$experiment['experiment_execution_authorized'],'Experiment native readback did not preserve protocol and fences.');
    $assert($register->invoke($actor,$experimentInput,$experimentKey,'fixture')===$experiment
        &&$getExperiment->invoke($actor,['experiment_id'=>$experiment['id']],'fixture','fixture')===$experiment,'Experiment idempotency or native readback failed.');
    $deny(fn()=>$register->invoke($actor,[...$experimentInput,'candidate_plan_id'=>$confounded['id']],$experimentKey,'fixture'),'Changed idempotent registration admitted.');
    $deny(fn()=>$register->invoke($actor,[...$experimentInput,'candidate_plan_id'=>$confounded['id']],'confounded-'.$token,'fixture'),'Two-factor change admitted.');
    $deny(fn()=>$register->invoke($actor,['control_plan_id'=>$candidate['id'],'candidate_plan_id'=>$control['id'],'factor'=>'HEADLINE'],'reverse-'.$token,'fixture'),'Duplicate arm pair admitted.');
    $outsider=new TenantContext(UserId::fromString($owner),OrganizationId::fromString('foreign-'.$token),
        OrganizationRole::fromString('manager'),[Permission::fromString(TenantPermissions::ACCESS),Permission::fromString(TenantPermissions::MANAGE)]);
    $deny(fn()=>$getExperiment->invoke($outsider,['experiment_id'=>$experiment['id']],'fixture','fixture'),'Cross-tenant protocol leaked.');
    $deny(fn()=>$creativePreview->invoke($actor,['experiment_id'=>$experiment['id'],'control_snapshot_ref'=>$snapshotId,
        'candidate_snapshot_ref'=>$snapshotId],'fixture','fixture'),'Future experiment ranked with unrelated snapshots.');
    $assert(count($metaTransport->writes)===$writesBefore,'Experiment design or preview mutated provider.');
    // Historical synthetic records: exercise the positive native terminal analytics path without
    // advancing wall-clock or pretending that a provider randomized study has been verified.
    $historicalFrom=gmdate('Y-m-d',time()-14*86400);$historicalTo=gmdate('Y-m-d',time()-8*86400);
    $historicalRegistered=gmdate('Y-m-d H:i:s',time()-16*86400);
    $planStore=new \Domains\Marketing\Infrastructure\Persistence\MySql\MysqlMarketingPaidLaunchPlanStore($pdo);
    $historicalPlan=static function(array $future,string $label)use($org,$owner,$token,$planStore,$historicalFrom,$historicalTo,$historicalRegistered,$pdo):array{
        $facts=array_intersect_key($future,array_flip(['campaign_id','campaign_revision','adset_id','adset_revision','adset_content_hash',
            'account_id','account_revision','currency','account_timezone']));
        $facts=[...$facts,'period_start'=>$historicalFrom,'period_end'=>$historicalTo,
            ...\Domains\Marketing\Domain\MarketingPaidFundingPolicy::budget('12.50','USD',$historicalFrom,$historicalTo)];
        $row=$planStore->create($org,$owner,'history-paid-'.$label.'-'.$token,hash('sha256','history-paid-'.$label.'-'.$token),$facts);
        $pdo->prepare('UPDATE marketing_paid_launch_plans SET created_at=? WHERE organization_id=? AND id=?')
            ->execute([$historicalRegistered,$org,$row['id']]);
        return $row;
    };
    $hc=$historicalPlan($control,'control');$hv=$historicalPlan($candidate,'candidate');
    $otherAudience=$planning->saveAudience($org,$owner,null,null,$planningPolicy->audience([
        'name'=>'Other experimental audience','target_spec'=>['market_countries'=>['PL'],'audience_hypothesis'=>'General retail demand']
    ]),'experiment-other-audience-'.$token);
    $ha=$historicalPlan($makeArm('audience','Control headline','Identical body text.',$otherAudience),'audience');
    $historicalProtocol=\Domains\Marketing\Domain\MarketingExperimentPolicy::protocol(
        $fundPlans->experimentPins($actor,$hc['id'],'HEADLINE'),$fundPlans->experimentPins($actor,$hv['id'],'HEADLINE'),
        'HEADLINE',substr($historicalRegistered,0,10));
    $historicalId='mkex-'.bin2hex(random_bytes(12));$historyIds=[$hc['id'],$hv['id']];sort($historyIds,SORT_STRING);
    $digest=\Domains\Marketing\Domain\MarketingExperimentPolicy::class;
    $pdo->prepare('INSERT INTO marketing_experiment_protocols (organization_id,id,actor_id,idempotency_key,request_hash,pair_hash,protocol_hash,protocol_json,created_at)
        VALUES (?,?,?,?,?,?,?,?,?)')->execute([$org,$historicalId,$owner,'history-experiment-'.$token,hash('sha256','history-experiment-'.$token),
        $digest::digest($historyIds),$digest::digest($historicalProtocol),json_encode($historicalProtocol,JSON_THROW_ON_ERROR),$historicalRegistered]);
    $seedTerminal=static function(array $plan,string $providerCampaign,string $providerAdset,string $providerAd)use($org,$journal):void{
        foreach(['DEPLOY_PAUSED'=>['CREATE_CAMPAIGN','CREATE_ADSET','CREATE_AD'],'ACTIVATE'=>['ACTIVATE_AD','ACTIVATE_ADSET','ACTIVATE_CAMPAIGN'],
            'PAUSE'=>['PAUSE_CAMPAIGN']] as $op=>$steps){
            $action=bin2hex(random_bytes(16));$journal->beginCommand($org,$plan['id'],$op,$action,hash('sha256',$action.':'.$op));
            foreach($steps as $step){
                $remote=str_ends_with($step,'CAMPAIGN')?$providerCampaign:(str_ends_with($step,'ADSET')?$providerAdset:$providerAd);
                $journal->begin($org,$plan['id'],$step,$action,hash('sha256',$action.':'.$step));
                $journal->identify($org,$plan['id'],$step,$action,$remote);
                $journal->finish($org,$plan['id'],$step,$action,['provider_id'=>$remote,'configured_status'=>$op==='ACTIVATE'?'ACTIVE':'PAUSED']);
            }
            $journal->identify($org,$plan['id'],$op,$action,$providerCampaign);
            $journal->finish($org,$plan['id'],$op,$action,['provider_campaign_id'=>$providerCampaign,'plan_id'=>$plan['id'],
                'operation'=>$op,'state'=>$op==='ACTIVATE'?'ACTIVE_CONFIGURED':'PAUSED','spend_confirmed'=>false]);
        }
    };
    $seedTerminal($hc,'91001','91002','91003');$seedTerminal($hv,'92001','92002','92003');$seedTerminal($ha,'93001','93002','93003');
    $accounts->importInsights($org,$account['id'],'campaign',$historicalFrom,$historicalTo,[
        ['campaign_id'=>'91001','date_start'=>$historicalFrom,'date_stop'=>$historicalTo,'spend'=>'40.00','impressions'=>'2000','clicks'=>'50'],
        ['campaign_id'=>'92001','date_start'=>$historicalFrom,'date_stop'=>$historicalTo,'spend'=>'40.00','impressions'=>'2000','clicks'=>'100'],
        ['campaign_id'=>'93001','date_start'=>$historicalFrom,'date_stop'=>$historicalTo,'spend'=>'40.00','impressions'=>'2000','clicks'=>'125']]);
    $historicalSnapshots=array_column($accounts->insights($org,$account['id'],'campaign',$historicalFrom,$historicalTo),'id','external_id');
    $obs=$creativePreview->invoke($actor,['experiment_id'=>$historicalId,'control_snapshot_ref'=>$historicalSnapshots['91001'],
        'candidate_snapshot_ref'=>$historicalSnapshots['92001']],'fixture','fixture');
    $assert($obs['status']==='REVIEW_OBSERVED_DIFFERENCE'&&$obs['lower_observed_cpc_plan_id']===$hv['id']&&!$obs['randomization_verified']
        &&!$obs['winning_creative_confirmed']&&!$obs['provider_mutation'],'Native historical creative source was not observed or overclaimed.');
    $historyBudget=new \Domains\Marketing\Application\Service\MarketingBudgetOptimizationService($fundPlans,$read,$performance);
    $budgetCandidate=$historyBudget->preview($actor,$hc['id'],$historicalSnapshots['91001'],$hv['id'],$historicalSnapshots['92001']);
    $assert($budgetCandidate['status']==='REVIEW_BOUNDED_REALLOCATION'&&$budgetCandidate['shift_minor']===125&&$budgetCandidate['total_daily_minor']===2500
        &&!$budgetCandidate['provider_mutation']&&!$budgetCandidate['financial_mutation'],'Native historical budget preview lost conservation or overclaimed execution.');
    $audienceCandidate=$audienceComparison->invoke($actor,['first_plan_id'=>$hc['id'],'first_snapshot_ref'=>$historicalSnapshots['91001'],
        'second_plan_id'=>$ha['id'],'second_snapshot_ref'=>$historicalSnapshots['93001']],'fixture','fixture');
    $assert($audienceCandidate['status']==='REVIEW_OBSERVED_DIFFERENCE'&&$audienceCandidate['lower_observed_cpc_plan_id']===$ha['id']
        &&!$audienceCandidate['actual_audience_delivery_confirmed']&&!$audienceCandidate['targeting_change_recommended'],
        'Native historical audience preview lost same-creative pinned sources or overclaimed delivery.');
    $deny(fn()=>$experiments->observe($actor,$historicalId,$historicalSnapshots['92001'],$historicalSnapshots['91001']),
        'Swapped native historical snapshots accepted.');
    $assert(count($metaTransport->writes)===$writesBefore,'Positive native observation performed a provider write.');
    echo "Marketing M5.4 native terminal budget conservation, M5.5 pinned audience and M5.7 historical creative observation PASS.\\n";

    // Governed creation from actual terminal native evidence; approval does not fund or deploy.
    $replacementReceipts=new \Domains\Marketing\Infrastructure\Persistence\MySql\MysqlMarketingOptimizationReceiptStore($pdo);
    $replacementService=new \Domains\Marketing\Application\Service\MarketingOptimizationReplacementService($historyBudget,
        new \Domains\Marketing\Application\Service\MarketingAudienceOptimizationService($fundPlans,$historyBudget),
        $fundPlans,new \Domains\Marketing\Application\Service\MarketingPlanningService($planning,$planningPolicy),$planningPolicy,$planning,$replacementReceipts,$modules);
    $replacementHandler=new \Domains\Marketing\Automation\Action\MarketingOptimizationReplacementHandler($replacementService,$worker,$identityResolver,$tenantFactory,$modules,$capabilities);
    $replacementDomains=new DomainModuleRegistry([new \Domains\Marketing\Bootstrap\MarketingDomainModule($handler,$paidHandler,replacement:$replacementHandler)]);
    $replacementBindings=new FederationCapabilityBindingResolver($capabilities,$replacementDomains,$modules);
    $replacementGate=new ModuleActionExecutionGate($replacementDomains,$modules,null,$worker);
    $replacementActions=new ActionService(new MysqlActionRepository($pdo),new ActionExecutor([$handler,$paidHandler,$replacementHandler],$replacementGate));
    $replacementContexts=new PolicyContextBuilder($replacementDomains);
    $replacementPolicies=new ActionPolicyService($replacementActions,$policies,new MysqlPolicyEvaluationRepository($pdo),$approvalRepo,$engine,
        $transactions,null,$replacementDomains,$modules,$replacementContexts);
    $replacementOutcome=new \App\Persistence\Marketing\MarketingOptimizationReplacementOutcomeProvider($db,$replacementReceipts,$receipts,$replacementService);
    $replacementGoals=new FederationGoalStore($db,$replacementBindings,new GoalPlanValidator($capabilities),new GoalOutcomeEvaluator(),
        new FederationTrustedOutcomeEvidenceResolver($modules,[$replacementOutcome]),$planReader);
    $replacementBridge=new FederationCanonicalWorkflowBridge($db,$store,$scheduler,$replacementGoals,$planEvidence,$receipts,
        new FailClosedWorkflowNonActionReceiptEvidence(),$planReader,$capabilities,$replacementDomains,$modules,$policies,$engine,$replacementContexts,$replacementPolicies,
        new DoctrineWorkflowExecutionCoordinator($db,$store,$scheduler,$planEvidence,$receipts));
    $replacementProposal=new \App\Persistence\Marketing\MarketingOptimizationReplacementPlanProposalService($db,$replacementGoals,$replacementBridge,$replacementService);
    $replacementCap=\Domains\Marketing\Automation\Action\MarketingOptimizationReplacementHandler::TYPE;
    $pdo->prepare('INSERT INTO cos_policies (id,organization_id,code,name,action_type,conditions,decision,priority,version,status)
        VALUES (?,?,?,?,?,?,"APPROVAL_REQUIRED",1,1,"ACTIVE")')->execute([bin2hex(random_bytes(16)),$org,'replacement-approval','Independent replacement approval',$replacementCap,'[]']);
    $replacementStart=gmdate('Y-m-d',time()+14*86400);$replacementEnd=gmdate('Y-m-d',time()+20*86400);
    $countPaidPlans=static fn()=>(int)$db->fetchOne('SELECT COUNT(*) FROM marketing_paid_launch_plans WHERE organization_id=?',[$org]);
    $noExternal=count($metaTransport->writes);
    foreach(['BUDGET'=>[$hv,'92001'],'AUDIENCE'=>[$ha,'93001']] as $mode=>[$other,$remote]){
        $p=['mode'=>$mode,'first_plan_id'=>$hc['id'],'first_snapshot_ref'=>$historicalSnapshots['91001'],
            'second_plan_id'=>$other['id'],'second_snapshot_ref'=>$historicalSnapshots[$remote],'future_start'=>$replacementStart,'future_end'=>$replacementEnd];
        $input=['target_type'=>'marketing_paid_account','target_id'=>$account['id'],'parameters'=>$p];
        $before=$countPaidPlans();$draft=$replacementService->prepare($actor,$account['id'],$p);
        $deny(fn()=>$replacementService->record($actor,$account['id'],[...$p,'decision_hash'=>str_repeat('0',64)],'wf:'.str_repeat('1',64),str_repeat('1',32)),
            'Unpinned replacement recorded.');
        $assert($countPaidPlans()===$before,'Stale replacement created a paid plan.');
        $prop=$replacementProposal->propose($actor,$input,'replacement-'.$mode.'-'.$token);
        $assert($prop['decision_hash']===$draft['decision_hash']&&$countPaidPlans()===$before,'Proposal created paid candidates before approval.');
        $gid=$prop['goal_id'];$pid=$prop['plan_id'];$pa=bin2hex(random_bytes(16));$rid='run-replacement-'.strtolower($mode).'-'.$token;
        $deny(fn()=>$replacementBridge->start($actor,$rid,$pid,$pa),'Unapproved replacement Plan started.');
        $db->executeStatement('UPDATE cos_federation_plans SET state=:state WHERE organization_id=:org AND plan_id=:plan',['state'=>'approved','org'=>$org,'plan'=>$pid]);
        $stored=(string)$db->fetchOne('SELECT plan_json FROM cos_federation_plans WHERE organization_id=:org AND plan_id=:plan',['org'=>$org,'plan'=>$pid]);
        $db->insert('cos_actions',['id'=>$pa,'organization_id'=>$org,'type'=>'cos.federation.plan.approval',
            'target_type'=>'cos_federation_plan','target_id'=>$pid,'parameters'=>json_encode(['goal_id'=>$gid,'plan_id'=>$pid,'specification_version'=>1,
                'plan_hash'=>hash('sha256',$stored)],JSON_THROW_ON_ERROR),'source_type'=>'USER','source_id'=>$owner,'status'=>'COMPLETED',
            'execution_mode'=>'APPROVAL_REQUIRED','risk_level'=>'HIGH','idempotency_key'=>'plan:replacement:'.$mode.':'.$token,'correlation_id'=>'replacement-'.$mode.'-'.$token]);
        $db->insert('cos_policy_evaluations',['id'=>bin2hex(random_bytes(16)),'organization_id'=>$org,'action_id'=>$pa,'decision'=>'APPROVAL_REQUIRED',
            'correlation_id'=>'replacement-'.$mode.'-'.$token,'evaluated_at'=>gmdate('Y-m-d H:i:s.u')]);
        $db->insert('cos_approvals',['id'=>bin2hex(random_bytes(16)),'organization_id'=>$org,'action_id'=>$pa,'status'=>'APPROVED','approver_type'=>'USER',
            'approver_id'=>$reviewer,'requested_by_type'=>'USER','requested_by_id'=>$owner,'decided_by_type'=>'USER','decided_by_id'=>$reviewer,
            'decided_at'=>gmdate('Y-m-d H:i:s.u'),'expires_at'=>(new DateTimeImmutable('+1 day'))->format('Y-m-d H:i:s.u')]);
        $replacementBridge->start($actor,$rid,$pid,$pa);$replacementBridge->advance($actor,$rid,$pa);
        $pending=$replacementBridge->advance($actor,$rid,$pa);
        $assert($pending['state']==='awaiting_human_approval'&&$countPaidPlans()===$before,'Replacement bypassed Action approval.');
        $act=$pending['action_id'];$assert($replacementActions->execute($org,$act,'replacement-premature')===null,'Unapproved replacement executed.');
        $ap=(string)$db->fetchOne('SELECT id FROM cos_approvals WHERE organization_id=? AND action_id=?',[$org,$act]);
        $assert($approvalRepo->decide($org,$ap,ApprovalStatus::Approved,$reviewer,'Independent replacement fixture'),'Replacement approval failed.');
        $replacementActions->queue($org,$act);
        $assert($replacementActions->execute($org,$act,'replacement-approved')?->status===ActionStatus::Completed,'Approved replacement failed.');
        $assert($replacementBridge->advance($actor,$rid,$pa)['state']==='step_completed'&&$replacementBridge->advance($actor,$rid,$pa)['state']==='completed',
            'Replacement workflow did not record a verified receipt.');
        $ev=$replacementGoals->recordEvaluation($actor,'replacement-eval-'.strtolower($mode).'-'.$token,$rid);
        $assert(($ev['result']??null)==='satisfied','Replacement Outcome not independently verified.');
        $r=$replacementOutcome->verifiedReceipt($actor,$rid);
        $assert($r['drafts_recorded']&&!$r['financial_mutation']&&!$r['provider_mutation']&&!$r['paid_activation_approved']
            &&$countPaidPlans()===$before+($mode==='BUDGET'?2:1)&&count($metaTransport->writes)===$noExternal,'Replacement mutated provider or did not create exact candidates.');
        if($mode==='BUDGET')$assert(array_sum(array_column($r['plans'],'daily_minor'))===2500&&array_sum(array_column($r['plans'],'lifetime_minor'))===17500,
            'Budget replacement did not conserve total funding.');
        else $assert($r['plans'][0]['source_plan_id']===$hc['id']&&$r['plans'][0]['audience_id']===$otherAudience['id']&&$r['plans'][0]['daily_minor']===1250,
            'Audience replacement changed the wrong target or funding.');
    }
    echo "Marketing M5.4/M5.5 canonical Plan and Action approvals, atomic native replacement drafts and trusted Outcome PASS.\n";
    echo "Marketing M5.6 native immutable creative experiment registration, one-factor fences and M5.7 premature observation rejected PASS\n";
    $performanceContexts=new \Domains\Marketing\Application\Service\MarketingAnalystContextService($campaignService,
        new \Domains\Marketing\Application\Service\MarketingPlanningService($planning,$planningPolicy),
        new \Domains\Marketing\Application\Service\MarketingProductDraftCheckService($product),$performance);
    $performanceContext=$performanceContexts->build($actor,$campaign['id'],1,$creative['id'],1,$adset['id'],1,$paidPlan['id'],$snapshotId);
    $perf=$performanceContext['context']['facts']['performance'];
    $assert($perf['status']==='NATIVE_BOUND_PROVIDER_REPORT'&&$perf['reported_spend_decimal']==='12.500000'&&$perf['recognized_revenue']===null
        &&$perf['payment_confirmed']===false&&!$performanceContext['context']['constraints']['provider_evidence_verified'],'M5 context promoted media facts into financial proof.');
    $pins=array_column($performanceContext['context']['sources'],null,'alias');
    $assert($pins['paid_plan']['content_hash']===$paidPlan['plan_hash']&&$pins['media_snapshot']['record_id']===$snapshotId,'M5 evidence pins missing.');
    $assert($performanceContexts->build($actor,$campaign['id'],1,$creative['id'],1,$adset['id'],1,$paidPlan['id'],$snapshotId)['context_hash']===$performanceContext['context_hash'],'M5 context unstable.');
    $deny(fn()=>$performanceContexts->build($actor,$campaign['id'],1,$creative['id'],1,$adset['id'],1,$paidPlan['id'],'mis-'.str_repeat('0',24)),'Foreign media snapshot accepted by analyst.');
    echo "Marketing M5 native bound paid campaign analytics context and immutable evidence pins PASS\n";
    $assert($transport->posts===0,'Hermes unexpectedly invoked by paid lifecycle.');
    $deny(fn()=>$planner->propose($actor,$paidPlan['id'],'activate','reactivation-'.$token,$envelope['id'],$reservation['id']),'Paused plan reactivated.');

    // Close the fixture's clock only for the pure Finance policy, not the
    // production clock or immutable Marketing dates. Native journal and snapshot are real MySQL.
    $policy=new \Domains\Finance\Domain\FinancePaidSettlementPolicy(static fn()=>new DateTimeImmutable($end.'T00:00:00Z +2 days'));
    $settlements=new \Domains\Finance\Infrastructure\Persistence\MySql\MysqlFinancePaidSettlementRepository($pdo,$policy);
    $reviewStore=new \Domains\Finance\Infrastructure\Persistence\MySql\MysqlFinancePaidSettlementReviewStore($pdo);
    $documents=new \Infrastructure\Platform\Persistence\MySql\Documents\MysqlDocumentsRepository($pdo);
    $docId='DOC-'.bin2hex(random_bytes(12));$fileId='FILE-'.bin2hex(random_bytes(12));$versionId='VER-'.bin2hex(random_bytes(12));
    $storageKey='documents/'.substr(hash('sha256',$org),0,20).'/'.$docId.'/'.$fileId.'/fixture.pdf';
    $pdf="%PDF-1.4\nSynthetic provider invoice 90001 12.50 USD\n%%EOF";
    $documents->createDocument(['organization_id'=>$org,'document_id'=>$docId,'title'=>'Synthetic invoice','status'=>'active','created_by'=>(int)$owner,'updated_by'=>(int)$owner],
        ['organization_id'=>$org,'file_id'=>$fileId,'storage_key'=>$storageKey,'mime_type'=>'application/pdf','size_bytes'=>strlen($pdf),'sha256'=>hash('sha256',$pdf),'created_by'=>(int)$owner],
        ['organization_id'=>$org,'version_id'=>$versionId,'document_id'=>$docId,'version_number'=>1,'file_id'=>$fileId,'source_type'=>'upload','created_by'=>(int)$owner]);
    $storage=new class($storageKey,$pdf) implements \Platform\Storage\Contract\FileStorageInterface {
        public function __construct(private string $key,private string $bytes){}
        public function put(string $key,string $contents,?string $contentType=null,array $metadata=[]):\Platform\Storage\Model\StoredFile{throw new DomainException('Fixture read only.');}
        public function read(string $key):string{if($key!==$this->key)throw new DomainException('Missing fixture PDF.');return $this->bytes;}
        public function exists(string $key):bool{return $key===$this->key;}
        public function delete(string $key):void{throw new DomainException('Fixture read only.');}
    };
    $source=new \App\Infrastructure\Finance\NativePaidSettlementSourceAdapter(
        new \App\Infrastructure\Finance\MarketingPaidSettlementReadAdapter($read),
        new \App\Infrastructure\Finance\DocumentsPaidSettlementReadAdapter(new \Domains\Documents\Application\Service\VerifiedUploadedPdfReadService($documents,$storage,$modules,$pdo)));
    $reviews=new \Domains\Finance\Application\Service\FinancePaidSettlementReviewService($financeRepo,$financeRepo,$source,$reviewStore,$policy,$modules);
    $reviewActor=new TenantContext(UserId::fromString($reviewer),OrganizationId::fromString($org),OrganizationRole::fromString('manager'),
        [Permission::fromString(TenantPermissions::ACCESS),Permission::fromString(TenantPermissions::MANAGE)]);
    $preview=$reviews->preview($actor,$reservation['id'],$docId,$snapshotId);$o=$preview['facts']['observation'];
    $reviewArgs=[$reservation['id'],$docId,$snapshotId,$preview['file']['sha256'],$o['source_hash'],$o['source_revision'],'META-fixture-invoice',1250];
    $deny(fn()=>$reviews->review($actor,...$reviewArgs),'Uploader/claimant self review.');
    $deny(fn()=>$reviews->read($actor,$reservation['id'],$docId,$snapshotId),'Unreviewed provider document.');
    $realPolicy=new \Domains\Finance\Domain\FinancePaidSettlementPolicy();
    $early=new \Domains\Finance\Application\Service\FinancePaidSettlementReviewService($financeRepo,$financeRepo,$source,$reviewStore,$realPolicy,$modules);
    $deny(fn()=>$early->review($reviewActor,...$reviewArgs),'Settlement before real period closure.');
    $statusService=new \Domains\Finance\Application\Service\FinancePaidSettlementStatusService($reviews,$settlements,$modules);
    $assert($statusService->status($actor,$reservation['id'],$docId,$snapshotId)['state']==='REVIEW_REQUIRED','Unreviewed status not fenced.');
    $reviews->review($reviewActor,...$reviewArgs);
    $assert($statusService->status($actor,$reservation['id'],$docId,$snapshotId)['state']==='MATCHED_NOT_SETTLED','Review status overclaims settlement.');
    $facts=$reviews->read($actor,$reservation['id'],$docId,$snapshotId);
    $assert($facts['candidate']['proposed_commit_minor']===1250&&!$facts['candidate']['finance_settled'],'Review must not mutate budget.');
    $financialWorker=new DoctrineWorkflowActionWorkerAdmission($db,$planEvidence,$capabilities,$modules,
        new WorkflowPredecessorReceiptVerifier($receipts,new FailClosedWorkflowNonActionReceiptEvidence()),
        $policies,$engine,$policyContexts,
        new \App\Infrastructure\Persistence\MySql\MysqlIdentityRepository($pdo),
        new \Kernel\Tenant\Service\TenantContextFactory(new \App\Security\RoleTenantPermissionResolver()));
    $settlementHandler=new \Domains\Finance\Automation\Action\FinancePaidSettlementHandler(
        new \Domains\Finance\Application\Service\ApprovedFinancePaidSettlementService($settlements,$reviews,$financialWorker,$modules),$identityResolver,$tenantFactory);
    $domains=new DomainModuleRegistry([new \Domains\Marketing\Bootstrap\MarketingDomainModule($handler,$paidHandler),
        new \Domains\Finance\Bootstrap\FinanceDomainModule(new \Domains\Finance\Automation\Action\FinanceBudgetActionHandler($financeOps),settlement:$settlementHandler)]);
    $bindings=new FederationCapabilityBindingResolver($capabilities,$domains,$modules);
    $gate=new ModuleActionExecutionGate($domains,$modules,null,$financialWorker);
    $actionService=new ActionService(new MysqlActionRepository($pdo),new ActionExecutor([$handler,$paidHandler,$settlementHandler],$gate));
    $policyContexts=new PolicyContextBuilder($domains);
    $policyService=new ActionPolicyService($actionService,$policies,new MysqlPolicyEvaluationRepository($pdo),$approvalRepo,$engine,
        $transactions,null,$domains,$modules,$policyContexts);
    $settlementOutcome=new \App\Persistence\Finance\FinancePaidSettlementOutcomeProvider($db,$settlements,$receipts);
    $goals=new FederationGoalStore($db,$bindings,new GoalPlanValidator($capabilities),new GoalOutcomeEvaluator(),
        new FederationTrustedOutcomeEvidenceResolver($modules,[$outcome,$settlementOutcome]),$planReader);
    $bridge=new FederationCanonicalWorkflowBridge($db,$store,$scheduler,$goals,$planEvidence,$receipts,
        new FailClosedWorkflowNonActionReceiptEvidence(),$planReader,$capabilities,$domains,$modules,$policies,$engine,$policyContexts,$policyService,
        new DoctrineWorkflowExecutionCoordinator($db,$store,$scheduler,$planEvidence,$receipts));
    $proposer=new \App\Persistence\Finance\FinancePaidSettlementGoalProposalService($db,$goals,$bridge,$reviews);
    $cap=\Domains\Finance\Application\Service\ApprovedFinancePaidSettlementService::TYPE;
    $pdo->prepare('INSERT INTO cos_policies (id,organization_id,code,name,action_type,conditions,decision,priority,version,status)
        VALUES (?,?,?,?,?,?,"APPROVAL_REQUIRED",1,1,"ACTIVE")')->execute([bin2hex(random_bytes(16)),$org,'settlement','Finance approval',$cap,'[]']);
    $candidate=$facts['candidate'];
    $proposal=$proposer->propose($actor,$reservation['id'],$docId,$snapshotId,$candidate['candidate_hash'],$facts['expected_revision'],'settlement-'.$token);
    $goalId=$proposal['goal_id'];$planId=$proposal['plan_id'];$planApproval=bin2hex(random_bytes(16));$runId='run-settle-'.$token;
    $db->executeStatement('UPDATE cos_federation_plans SET state=:state WHERE organization_id=:org AND plan_id=:plan',['state'=>'approved','org'=>$org,'plan'=>$planId]);
    $storedPlan=(string)$db->fetchOne('SELECT plan_json FROM cos_federation_plans WHERE organization_id=:org AND plan_id=:plan',['org'=>$org,'plan'=>$planId]);
    $db->insert('cos_actions',['id'=>$planApproval,'organization_id'=>$org,'type'=>'cos.federation.plan.approval',
        'target_type'=>'cos_federation_plan','target_id'=>$planId,'parameters'=>json_encode(['goal_id'=>$goalId,'plan_id'=>$planId,
            'specification_version'=>1,'plan_hash'=>hash('sha256',$storedPlan)],JSON_THROW_ON_ERROR),
        'source_type'=>'USER','source_id'=>$owner,'status'=>'COMPLETED','execution_mode'=>'APPROVAL_REQUIRED','risk_level'=>'HIGH',
        'idempotency_key'=>'plan:settlement:'.$token,'correlation_id'=>'settlement-'.$token]);
    $db->insert('cos_policy_evaluations',['id'=>bin2hex(random_bytes(16)),'organization_id'=>$org,'action_id'=>$planApproval,
        'decision'=>'APPROVAL_REQUIRED','correlation_id'=>'settlement-'.$token,'evaluated_at'=>gmdate('Y-m-d H:i:s.u')]);
    $db->insert('cos_approvals',['id'=>bin2hex(random_bytes(16)),'organization_id'=>$org,'action_id'=>$planApproval,'status'=>'APPROVED',
        'approver_type'=>'USER','approver_id'=>$reviewer,'requested_by_type'=>'USER','requested_by_id'=>$owner,'decided_by_type'=>'USER',
        'decided_by_id'=>$reviewer,'decided_at'=>gmdate('Y-m-d H:i:s.u'),'expires_at'=>(new DateTimeImmutable('+1 day'))->format('Y-m-d H:i:s.u')]);
    $bridge->start($actor,$runId,$planId,$planApproval);
    $bridge->advance($actor,$runId,$planApproval);
    $pending=$bridge->advance($actor,$runId,$planApproval);
    $assert($pending['state']==='awaiting_human_approval','Settlement lacks separate Action Approval.');
    $actionId=$pending['action_id'];
    $assert($actionService->execute($org,$actionId,'premature-settlement')===null&&$settlements->receipt($org,$reservation['id'])===null,'Unapproved settlement executed.');
    $approvalId=(string)$db->fetchOne('SELECT id FROM cos_approvals WHERE organization_id=:org AND action_id=:action',['org'=>$org,'action'=>$actionId]);
    $assert($approvalRepo->decide($org,$approvalId,ApprovalStatus::Approved,$reviewer,'Independent settlement fixture'),'Approval failed.');
    $actionService->queue($org,$actionId);
    $assert($actionService->execute($org,$actionId,'settlement-approved-worker')?->status===ActionStatus::Completed,'Financial canonical worker failed.');
    $assert($bridge->advance($actor,$runId,$planApproval)['state']==='step_completed','Settlement receipt not verified.');
    $assert($bridge->advance($actor,$runId,$planApproval)['state']==='completed','Settlement Workflow incomplete.');
    $evaluation=$goals->recordEvaluation($actor,'settlement-eval-'.$token,$runId);
    $assert(($evaluation['result']??null)==='satisfied','Native Finance outcome not satisfied.');
    $receipt=$settlements->receipt($org,$reservation['id']);$balance=$financeRepo->envelope($org,$envelope['id']);
    $assert($receipt['committed_minor']===1250&&$balance['reserved_minor']===0&&$balance['committed_minor']===1250
        &&$balance['available_minor']===$paidPlan['lifetime_minor']-1250&&!$receipt['payment_confirmed'],'Native settlement balance incorrect.');
    $assert($statusService->status($actor,$reservation['id'],$docId,$snapshotId)['state']==='SETTLED','Settlement status missing native receipt.');
    $assert($actionService->execute($org,$actionId,'settlement-replay')===null,'Completed financial Action replayed.');
    $analyticsFinance=new \App\Infrastructure\Marketing\FinanceAnalyticsReadAdapter($financeRepo,$settlements,$modules);
    $analyticsReceipt=$analyticsFinance->settlement($actor,$paidPlan['id'],$paidPlan['plan_hash'],$reservation['id']);
    $assert($analyticsReceipt['committed_minor']===1250&&$analyticsReceipt['finance_settled']===true,'M4 Finance native receipt readback failed.');
    $deny(fn()=>$analyticsFinance->settlement($actor,$paidPlan['id'],str_repeat('0',64),$reservation['id']),'M4 foreign plan hash accepted.');
    echo "Marketing M4 native Finance analytics receipt PASS\n";
    echo "Marketing M3 native Documents review, canonical financial Goal and independent Finance settlement outcome E2E PASS.\n";
    echo "Marketing M3 approved create-paused/activate/pause native MySQL E2E with isolated Meta fixture PASS.\n";
} finally {
    if($oldRollout===false)putenv('COS_FEDERATION_CANONICAL_ORGANIZATION');
    else putenv('COS_FEDERATION_CANONICAL_ORGANIZATION='.$oldRollout);
}
