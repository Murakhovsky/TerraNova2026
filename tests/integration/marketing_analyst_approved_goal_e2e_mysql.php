<?php
declare(strict_types=1);

/** Native MySQL canonical Marketing Goal/Action acceptance. Only Hermes transport and identity boundary are fixtures. */
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
$token=bin2hex(random_bytes(7));$org='mkt-goal-'.$token;
$owner='42';$reviewer='43';
$runId='run-mkt-'.$token;$planApproval=bin2hex(random_bytes(16));
$assert=static function(bool $ok,string $why):void{
    if(!$ok)throw new RuntimeException('Marketing approved Goal E2E: '.$why);
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
    $catalog=new ModuleCatalog([ModuleDefinition::fromArray($manifest),
        ModuleDefinition::fromArray((static fn(string $path):array=>require $path)($root.'/app/Domains/Sales/module.php')),
        ModuleDefinition::fromArray((static fn(string $path):array=>require $path)($root.'/app/Domains/Federation/module.php'))]);
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
    $captureStore=new \Domains\Marketing\Infrastructure\Persistence\MySql\MysqlMarketingAttributionStore($pdo);
    $commerceStore=new \Domains\Marketing\Infrastructure\Persistence\MySql\MysqlMarketingCommerceAttributionStore($pdo);
    $orderRepo=new \Domains\Sales\Infrastructure\Persistence\MySql\MysqlSalesOrderRepository($pdo);
    $orderRead=new \App\Infrastructure\Marketing\CommerceOrderReadAdapter($modules,$orderRepo,$orderRepo,$orderRepo);
    $leadRead=new \App\Infrastructure\Marketing\SalesAttributionReadAdapter(new \Domains\Sales\Infrastructure\ReadModel\MySql\MysqlSalesWorkspaceReadModel($pdo),$modules);
    $candidateRead=new \App\Infrastructure\Marketing\AttributionCandidateReadAdapter(new \Domains\Sales\Infrastructure\ReadModel\MySql\MysqlSalesAttributionCandidateReadModel($pdo),$modules);
    $funding=new class implements \Domains\Marketing\Application\Contract\MarketingFinanceFundingReadPort{
        public function observe(TenantContext $actor,string $envelopeId,string $reservationId):?array{throw new DomainException('No funding operation in sweep.');}
    };
    $sweepPlans=new \Domains\Marketing\Application\Service\MarketingPaidLaunchPlanService(
        new \Domains\Marketing\Infrastructure\Persistence\MySql\MysqlMarketingPaidLaunchPlanStore($pdo),$campaignService,
        new \Domains\Marketing\Application\Service\MarketingPlanningService($planning,$planningPolicy),
        new \Domains\Marketing\Infrastructure\Persistence\MySql\MysqlMarketingPaidReadRepository($pdo),$funding,$modules);
    $sweepService=new \Domains\Marketing\Application\Service\MarketingAttributionSweepService($campaignService,$sweepPlans,$candidateRead,$leadRead,$orderRead,$captureStore,$commerceStore,$modules);
    $sweepHandler=new \Domains\Marketing\Automation\Action\MarketingAttributionSweepHandler($sweepService,$worker,$identityResolver,$tenantFactory,$modules,$capabilities);
    $optAccounts=new \Domains\Marketing\Infrastructure\Persistence\MySql\MysqlMarketingPaidReadRepository($pdo);
    $optMediaStore=new \Domains\Marketing\Infrastructure\Persistence\MySql\MysqlMarketingMediaAnalyticsStore($pdo,$optAccounts);
    $optProvider=new class implements \Domains\Marketing\Application\Contract\PaidMediaProviderInterface{
        public function inspectAccount(string $organizationId,string $providerAccountId,string $credentialRef):array{throw new DomainException('No provider call in optimization review.');}
        public function hierarchy(string $organizationId,string $providerAccountId,string $credentialRef):array{throw new DomainException('No provider call in optimization review.');}
        public function insights(string $organizationId,string $providerAccountId,string $credentialRef,string $from,string $to,string $grain):array{throw new DomainException('No provider call in optimization review.');}
    };
    $optMedia=new \Domains\Marketing\Application\Service\MarketingMediaAnalyticsService($optAccounts,$optProvider,$optMediaStore,$modules);
    $ruleService=new \Domains\Marketing\Application\Service\MarketingOptimizationRuleService($optMedia);
    $optReceipts=new \Domains\Marketing\Infrastructure\Persistence\MySql\MysqlMarketingOptimizationReceiptStore($pdo);
    $optReviewService=new \Domains\Marketing\Application\Service\MarketingOptimizationReviewService($ruleService,$optReceipts,$modules);
    $optReviewHandler=new \Domains\Marketing\Automation\Action\MarketingOptimizationReviewHandler($optReviewService,$worker,$identityResolver,$tenantFactory,$modules,$capabilities);
    $optReviewOutcome=new \App\Persistence\Marketing\MarketingOptimizationReviewOutcomeProvider($db,$optReceipts,$receipts);
    $optHistory=new \App\Infrastructure\Marketing\MarketingOptimizationReviewEvidenceReader(new \App\Persistence\Federation\FederationTrustedActionOutcomeReader($db),$optReceipts);
    $learningService=new \Domains\Marketing\Application\Service\MarketingOptimizationLearningService($optHistory,$ruleService,$optReceipts,$modules);
    $learningHandler=new \Domains\Marketing\Automation\Action\MarketingOptimizationLearningHandler($learningService,$worker,$identityResolver,$tenantFactory,$modules,$capabilities);
    $learningOutcome=new \App\Persistence\Marketing\MarketingOptimizationLearningOutcomeProvider($db,$optReceipts,$receipts);
    $planHandler=new \App\Persistence\Federation\FederationPlanApproveHandler($db,$planReader);
    $domains=new DomainModuleRegistry([new \Domains\Marketing\Bootstrap\MarketingDomainModule($handler,null,$sweepHandler,$optReviewHandler,$learningHandler),new \Domains\Federation\Bootstrap\FederationDomainModule($planHandler)]);
    $bindings=new FederationCapabilityBindingResolver($capabilities,$domains,$modules);
    $gate=new ModuleActionExecutionGate($domains,$modules,null,$worker);
    $actionService=new ActionService(new MysqlActionRepository($pdo),new ActionExecutor([$handler,$sweepHandler,$optReviewHandler,$learningHandler,$planHandler],$gate));
    $approvalRepo=new MysqlApprovalRepository($pdo);
    $policies=new MysqlPolicyRepository($pdo);
    $engine=new PolicyEngine(new ConditionEvaluator());
    $policyContexts=new PolicyContextBuilder($domains);
    $policyService=new ActionPolicyService($actionService,$policies,new MysqlPolicyEvaluationRepository($pdo),$approvalRepo,$engine,
        $transactions,null,$domains,$modules,$policyContexts);
    $outcome=new MarketingAnalystRunOutcomeProvider($db,$runs,$receipts);
    $sweepOutcome=new \App\Persistence\Marketing\MarketingAttributionSweepOutcomeProvider($db,$captureStore,$receipts);
    $goals=new FederationGoalStore($db,$bindings,new GoalPlanValidator($capabilities),new GoalOutcomeEvaluator(),
        new FederationTrustedOutcomeEvidenceResolver($modules,[$outcome,$sweepOutcome,$optReviewOutcome,$learningOutcome]),$planReader);
    $scheduler=new WorkflowStepScheduler();
    $bridge=new FederationCanonicalWorkflowBridge($db,$store,$scheduler,$goals,$planEvidence,$receipts,
        new FailClosedWorkflowNonActionReceiptEvidence(),$planReader,$capabilities,$domains,$modules,$policies,$engine,$policyContexts,$policyService,
        new DoctrineWorkflowExecutionCoordinator($db,$store,$scheduler,$planEvidence,$receipts));
    $readiness=new HermesAnalystReadinessService($profiles,$budget,$modules);
    $planner=new MarketingAnalystPlanProposalService($db,$goals,$bridge,$contexts,$readiness);
    $preflight=new \App\Infrastructure\Marketing\HermesAnalystOperatorPreflight($profiles,
        new \Domains\Marketing\Infrastructure\Hermes\HermesAnalystProfileProbe($vault,$transport),$budget,$modules);
    $local=$preflight->inspect($org);
    $assert($local['status']==='LOCAL_PREFLIGHT_PASSED'&&$transport->reads===0&&$transport->posts===0,
        'Local preflight caused remote execution.');
    $remote=$preflight->inspect($org,true);
    $assert($remote['status']==='REMOTE_PREFLIGHT_PASSED_NOT_ACCEPTED'&&$remote['remote_probe_passed']
        &&!$remote['isolation_verified']&&!$remote['provider_cost_cap_verified']&&!$remote['release_authorized']
        &&$transport->reads===1&&$transport->posts===0&&$budget->monthlySpend($org,'USD')===0.0,
        'Remote preflight claimed acceptance or consumed generation budget.');
    $transport->reads=0;
    $campaign=$campaigns->createDraft($org,$owner,(new \Domains\Marketing\Domain\MarketingDraftPolicy())->validate([
        'name'=>'Canonical analyst acceptance','business_objective'=>'Improve product headline',
        'product_refs'=>[],'brief'=>'Planning advice only, no paid advertising.'
    ]),'mkt-campaign-'.$token);
    $creative=$planning->createCreative($org,$campaign['id'],$owner,$planningPolicy->creative([
        'headline'=>'Example product headline','primary_text'=>'Example marketing planning text without customer data.',
        'call_to_action'=>'Read more','destination_url'=>'https://example.com/catalog','language'=>'uk'
    ]),'mkt-creative-'.$token);
    $campaignId=$campaign['id'];$creativeId=$creative['id'];
    $assert($readiness->status($actor)['plan_available']&&!$readiness->status($actor)['live_verified'],'Readiness overclaims live verification.');
    $proposal=$planner->propose($actor,$campaignId,1,$creativeId,1,null,null,'mkt-goal-key-'.$token);
    $assert($proposal['state']==='proposed','Canonical Marketing Plan was not created.');
    $replay=$planner->propose($actor,$campaignId,1,$creativeId,1,null,null,'mkt-goal-key-'.$token);
    $assert($replay['plan_id']===$proposal['plan_id']&&$replay['state']==='existing','Plan replay created a duplicate.');
    $goalId=$proposal['goal_id'];$planId=$proposal['plan_id'];
    $assert($transport->posts===0&&$transport->reads===0&&$runs->listRuns($org,$campaignId)===[],'Plan creation executed Hermes.');
    $deny(fn()=>$bridge->start($actor,$runId,$planId,$planApproval),'Unapproved Plan started.');
    $pdo->prepare('INSERT INTO cos_policies
        (id,organization_id,code,name,action_type,conditions,decision,priority,version,status)
        VALUES (:id,:org,"mkt-analyst-e2e","Marketing independent Action approval",
                "marketing.analyst.run.request",:conditions,"APPROVAL_REQUIRED",1,1,"ACTIVE")')
        ->execute(['id'=>'policy-'.$token,'org'=>$org,'conditions'=>'[]']);
    // Independent Plan Approval evidence fixture, as in Property canonical E2E.
    $db->executeStatement('UPDATE cos_federation_plans SET state=:state
        WHERE organization_id=:org AND plan_id=:plan',
        ['state'=>'approved','org'=>$org,'plan'=>$planId]);
    $storedPlan=(string)$db->fetchOne('SELECT plan_json FROM cos_federation_plans
        WHERE organization_id=:org AND plan_id=:plan',['org'=>$org,'plan'=>$planId]);
    $db->insert('cos_actions',[
        'id'=>$planApproval,'organization_id'=>$org,'type'=>'cos.federation.plan.approval',
        'target_type'=>'cos_federation_plan','target_id'=>$planId,
        'parameters'=>json_encode(['goal_id'=>$goalId,'plan_id'=>$planId,
            'specification_version'=>1,'plan_hash'=>hash('sha256',$storedPlan)],JSON_THROW_ON_ERROR),
        'source_type'=>'USER','source_id'=>$owner,'status'=>'COMPLETED',
        'execution_mode'=>'APPROVAL_REQUIRED','risk_level'=>'HIGH',
        'idempotency_key'=>'plan:'.$token,'correlation_id'=>'mkt-plan-'.$token,
    ]);
    $db->insert('cos_policy_evaluations',[
        'id'=>bin2hex(random_bytes(16)),'organization_id'=>$org,'action_id'=>$planApproval,
        'decision'=>'APPROVAL_REQUIRED','correlation_id'=>'mkt-plan-'.$token,
        'evaluated_at'=>gmdate('Y-m-d H:i:s.u'),
    ]);
    $db->insert('cos_approvals',[
        'id'=>bin2hex(random_bytes(16)),'organization_id'=>$org,'action_id'=>$planApproval,
        'status'=>'APPROVED','approver_type'=>'USER','approver_id'=>$reviewer,
        'requested_by_type'=>'USER','requested_by_id'=>$owner,
        'decided_by_type'=>'USER','decided_by_id'=>$reviewer,
        'decided_at'=>gmdate('Y-m-d H:i:s.u'),
        'expires_at'=>(new DateTimeImmutable('+1 day'))->format('Y-m-d H:i:s.u'),
    ]);
    $assert($bridge->start($actor,$runId,$planId,$planApproval)['execution_authority']==='platform_workflow',
        'Platform Workflow was not sole execution authority');
    $deny(fn()=>$bridge->start($actor,'other-'.$token,$planId,$planApproval),
        'same Marketing Plan started twice');
    $assert($bridge->advance($actor,$runId,$planApproval)['state']==='running',
        'canonical Run did not advance to running');
    $pending=$bridge->advance($actor,$runId,$planApproval);
    $assert(($pending['state']??null)==='awaiting_human_approval',
        'approved Plan failed to create independently approvable Action');
    $actionId=$pending['action_id']??null;
    $assert(is_string($actionId)&&$actionId!=='','approved Marketing Action ID missing');
    $assert($actionService->execute($org,$actionId,'mkt-premature-worker')===null,
        'unapproved Marketing Action executed');
    $assert($transport->posts===0&&$runs->listRuns($org,$campaignId)===[],'Unapproved Action produced advice.');
    $assert($bridge->advance($actor,$runId,$planApproval)['action_id']===$actionId,
        'repeated advance created another Action');
    $approvalId=(string)$db->fetchOne('SELECT id FROM cos_approvals
        WHERE organization_id=:org AND action_id=:action',
        ['org'=>$org,'action'=>$actionId]);
    $assert($approvalId!=='','Action independent approval row missing');
    $assert($approvalRepo->decide($org,$approvalId,ApprovalStatus::Approved,$reviewer,'CI verified'),
        'independent Marketing Action approver decision failed');
    $actionService->queue($org,$actionId);
    $executed=$actionService->execute($org,$actionId,'mkt-approved-worker');
    $assert($executed?->status===ActionStatus::Completed,
        'approved Marketing analyst Action worker did not complete');
    $assert($actionService->execute($org,$actionId,'mkt-second-worker')===null,
        'completed Marketing Action was executed a second time');
    $native=$runs->listRuns($org,$campaignId);
    $assert(count($native)===1&&$native[0]['state']==='SUCCEEDED'&&$native[0]['advice']['runtime']==='HERMES_AGENT'
        &&!$native[0]['paid_activation_approved']&&!$native[0]['finance_cost_confirmed']
        &&$transport->posts===1&&$transport->reads===1,'Approved Action did not persist exactly one advisory receipt.');
    $assert(abs($budget->monthlySpend($org,'USD')-0.25)<0.000001,'Kernel budget receipt absent.');
    $assert($bridge->advance($actor,$runId,$planApproval)['state']==='step_completed',
        'native Marketing Action receipt failed canonical Step attestation');
    $assert($bridge->advance($actor,$runId,$planApproval)['state']==='completed',
        'Platform Workflow failed to complete after native advice');
    $outcomeResult=$goals->recordEvaluation($actor,'mkt-eval-'.$token,$runId);
    $assert(($outcomeResult['result']??null)==='satisfied'
        &&($outcomeResult['criteria'][0]['observed']??null)===1,
        'Goal was not independently satisfied by one native Marketing receipt');
    $assert((int)$db->fetchOne('SELECT COUNT(*) FROM cos_actions
        WHERE organization_id=:org AND type=:type',
        ['org'=>$org,'type'=>\Domains\Marketing\Automation\Action\MarketingAnalystRunHandler::TYPE])===1,
        'Marketing analyst submitted more than one canonical Action');
    $assert((int)$db->fetchOne('SELECT COUNT(*) FROM cos_federation_runs
        WHERE organization_id=:org',['org'=>$org])===0,
        'legacy executor duplicated canonical Run');
    $reviewService=new \Domains\Marketing\Application\Service\MarketingAnalystReviewService($runs,$contexts,$campaignService,$planningPolicy,$modules);
    $deny(fn()=>$reviewService->review($actor,$campaignId,$native[0]['id'],'ACCEPT','Self approval denied','mkt-self-'.$token),'Requester reviewed own advice.');
    $reviewActor=new TenantContext(UserId::fromString($reviewer),OrganizationId::fromString($org),OrganizationRole::fromString('manager'),
        [Permission::fromString(TenantPermissions::ACCESS),Permission::fromString(TenantPermissions::MANAGE)]);
    $decision=$reviewService->review($reviewActor,$campaignId,$native[0]['id'],'ACCEPT','Useful planning advice','mkt-review-'.$token);
    $assert($decision['decision']==='ACCEPT'&&!$decision['paid_activation_approved'],'Human review granted advertising authority.');
    // Native receipt tamper is deliberately introduced only in this disposable fixture.
    $db->executeStatement('UPDATE marketing_analyst_runs SET context_hash=:fake WHERE organization_id=:org AND id=:id',
        ['fake'=>str_repeat('0',64),'org'=>$org,'id'=>$native[0]['id']]);
    $deny(fn()=>$outcome->observeRun($actor,$runId,MarketingAnalystPlanProposalService::CRITERION,new DateTimeImmutable('-1 day'),new DateTimeImmutable('+1 day')),
        'Forged native context was counted as a trusted outcome.');
    $assert($transport->posts===1,'Readback or review caused another remote turn.');
    echo "Marketing approved Goal/Plan/Action/Outcome native MySQL E2E with Hermes fixture PASS.\\n";

    // Bounded native sweep through the same canonical Plan/Workflow/Action/Outcome path.
    // The Plan approval evidence is a fixture, as in the analyst case above.
    $sweepCap=\Domains\Marketing\Automation\Action\MarketingAttributionSweepHandler::TYPE;
    $criterion=\App\Persistence\Marketing\MarketingAttributionSweepOutcomeProvider::CRITERION;
    $sweepGoal='goal-sweep-'.$token;$sweepPlan='plan-sweep-'.$token;$sweepRun='run-sweep-'.$token;
    $sweepApproval=bin2hex(random_bytes(16));$sweepDate='2026-02-02';
    $insert=$pdo->prepare("INSERT INTO tn_leads (organization_id,full_name,status,utm_source,utm_medium,utm_campaign,created_at)
        VALUES (?,'Private sweep fixture','new','google','organic',?,?)");
    for($i=0;$i<51;$i++)$insert->execute([$org,$campaignId,$sweepDate.' 12:00:00']);
    $input=['target_type'=>'marketing_campaign','target_id'=>$campaignId,'parameters'=>[
        'plan_id'=>null,'source_kind'=>'lead','window_start'=>$sweepDate,'window_end'=>$sweepDate,'after'=>null,'max_pages'=>2]];
    $goals->createGoal($actor,new \Platform\Orchestration\Goal\GoalSpecification($sweepGoal,$org,$owner,
        'Record bounded native conversion sweep',[['id'=>$criterion,'operator'=>'at_least','expected'=>1]],[$sweepCap],1,null,null,'',[],['independent_plan','independent_action'],'always'));
    $goals->proposePlan($actor,$sweepPlan,$sweepGoal,[['id'=>'sweep','capability_id'=>$sweepCap,'capability_version'=>'1.0.0','input'=>$input]]);
    $deny(fn()=>$bridge->start($actor,$sweepRun,$sweepPlan,$sweepApproval),'Unapproved sweep plan started.');
    $assert($captureStore->list($org,$campaignId,$sweepDate,$sweepDate,501,0)===[],'Plan proposal captured evidence before approval.');
    $pdo->prepare('INSERT INTO cos_policies
        (id,organization_id,code,name,action_type,conditions,decision,priority,version,status)
        VALUES (?,?,"mkt-sweep-e2e","Marketing independent sweep approval",?,?,"APPROVAL_REQUIRED",1,1,"ACTIVE")')
        ->execute([bin2hex(random_bytes(16)),$org,$sweepCap,'[]']);
    $db->executeStatement('UPDATE cos_federation_plans SET state=:state WHERE organization_id=:org AND plan_id=:plan',
        ['state'=>'approved','org'=>$org,'plan'=>$sweepPlan]);
    $planJson=(string)$db->fetchOne('SELECT plan_json FROM cos_federation_plans WHERE organization_id=:org AND plan_id=:plan',['org'=>$org,'plan'=>$sweepPlan]);
    $db->insert('cos_actions',[
        'id'=>$sweepApproval,'organization_id'=>$org,'type'=>'cos.federation.plan.approval',
        'target_type'=>'cos_federation_plan','target_id'=>$sweepPlan,
        'parameters'=>json_encode(['goal_id'=>$sweepGoal,'plan_id'=>$sweepPlan,'specification_version'=>1,'plan_hash'=>hash('sha256',$planJson)],JSON_THROW_ON_ERROR),
        'source_type'=>'USER','source_id'=>$owner,'status'=>'COMPLETED','execution_mode'=>'APPROVAL_REQUIRED','risk_level'=>'HIGH',
        'idempotency_key'=>'plan-sweep:'.$token,'correlation_id'=>'mkt-sweep-plan-'.$token,
    ]);
    $db->insert('cos_policy_evaluations',[
        'id'=>bin2hex(random_bytes(16)),'organization_id'=>$org,'action_id'=>$sweepApproval,
        'decision'=>'APPROVAL_REQUIRED','correlation_id'=>'mkt-sweep-plan-'.$token,'evaluated_at'=>gmdate('Y-m-d H:i:s.u'),
    ]);
    $db->insert('cos_approvals',[
        'id'=>bin2hex(random_bytes(16)),'organization_id'=>$org,'action_id'=>$sweepApproval,
        'status'=>'APPROVED','approver_type'=>'USER','approver_id'=>$reviewer,'requested_by_type'=>'USER','requested_by_id'=>$owner,
        'decided_by_type'=>'USER','decided_by_id'=>$reviewer,'decided_at'=>gmdate('Y-m-d H:i:s.u'),
        'expires_at'=>(new DateTimeImmutable('+1 day'))->format('Y-m-d H:i:s.u'),
    ]);
    $assert($bridge->start($actor,$sweepRun,$sweepPlan,$sweepApproval)['execution_authority']==='platform_workflow','Sweep bypassed canonical workflow.');
    $assert($bridge->advance($actor,$sweepRun,$sweepApproval)['state']==='running','Sweep run failed to start.');
    $pending=$bridge->advance($actor,$sweepRun,$sweepApproval);
    $assert($pending['state']==='awaiting_human_approval','Sweep not independently approved.');
    $sweepAction=$pending['action_id'];
    $assert($actionService->execute($org,$sweepAction,'sweep-unapproved')===null&&$captureStore->list($org,$campaignId,$sweepDate,$sweepDate,501,0)===[],'Unapproved sweep mutated evidence.');
    $decisionId=(string)$db->fetchOne('SELECT id FROM cos_approvals WHERE organization_id=:org AND action_id=:action',['org'=>$org,'action'=>$sweepAction]);
    $assert($approvalRepo->decide($org,$decisionId,ApprovalStatus::Approved,$reviewer,'CI verified sweep'),'Sweep Action independent approval failed.');
    $actionService->queue($org,$sweepAction);
    $out=$actionService->executeOutcome($org,$sweepAction,'sweep-approved');
    $assert($out!==null&&$out->result->successful&&$out->result->data['scanned']===51&&$out->result->data['pages_processed']===2
        &&$out->result->data['recorded_or_already_present']===51&&!$out->result->data['complete_coverage'],'Approved native sweep failed.');
    $assert($actionService->execute($org,$sweepAction,'sweep-replay')===null,'Completed sweep replayed.');
    $assert(count($captureStore->list($org,$campaignId,$sweepDate,$sweepDate,501,0))===51,'Sweep did not persist native evidence.');
    $assert($bridge->advance($actor,$sweepRun,$sweepApproval)['state']==='step_completed','Sweep canonical receipt not attested.');
    $assert($bridge->advance($actor,$sweepRun,$sweepApproval)['state']==='completed','Sweep workflow not completed.');
    $evaluation=$goals->recordEvaluation($actor,'mkt-sweep-eval-'.$token,$sweepRun);
    $assert(($evaluation['result']??null)==='satisfied'&&($evaluation['criteria'][0]['observed']??null)===1,'Sweep Goal native receipt did not satisfy outcome.');
    $sweepKey=(string)$db->fetchOne('SELECT idempotency_key FROM cos_actions WHERE organization_id=? AND id=?',[$org,$sweepAction]);
    $db->executeStatement('UPDATE marketing_attribution_capture_intents SET request_hash=? WHERE organization_id=? AND idempotency_key=?',
        [str_repeat('0',64),$org,$sweepKey]);
    $deny(fn()=>$sweepOutcome->observeRun($actor,$sweepRun,$criterion,new DateTimeImmutable('-1 day'),new DateTimeImmutable('+1 day')),'Tampered sweep scope accepted as outcome.');
    echo "Marketing M4 canonical approved sweep Action, multipage native capture and independently read Goal receipt PASS (Plan evidence fixture).\n";
    // Scheduler uses real Plan approval Action, then independent sweep Action approval.
    $pdo->prepare('INSERT INTO cos_policies
        (id,organization_id,code,name,action_type,conditions,decision,priority,version,status)
        VALUES (?,?,"mkt-schedule-plan-e2e","Independent scheduled Plan approval",?,?,"APPROVAL_REQUIRED",1,1,"ACTIVE")')
        ->execute([bin2hex(random_bytes(16)),$org,'cos.federation.plan.approval','[]']);
    $coordinator=new \App\Persistence\Federation\FederationPlanApprovalCoordinator($db,$goals,$domains,$modules,
        new \Platform\Orchestration\Goal\GoalPlanApprovalRequestFactory(),$policyService,$policies,$policyContexts,$engine);
    $scheduler=new \App\Persistence\Marketing\MarketingAttributionScheduleService($db,$goals,$bridge,$coordinator,$sweepOutcome,$campaignService,$sweepPlans,$modules);
    $date='2026-02-03';
    for($i=0;$i<51;$i++)$insert->execute([$org,$campaignId,$date.' 12:00:00']);
    $profile=['profile_id'=>'fixture-sweep','organization_id'=>$org,'actor_id'=>$owner,'campaign_id'=>$campaignId,
        'plan_id'=>null,'source_kind'=>'lead','lookback_days'=>1,'max_pages'=>1];
    $clock=new DateTimeImmutable('2026-02-04T00:00:00Z');
    $tick=fn()=>$scheduler->tick($actor,$profile,$clock);
    $scheduleRow=fn()=>$db->fetchAssociative('SELECT * FROM marketing_attribution_schedules WHERE organization_id=? AND profile_id=?',[$org,$profile['profile_id']]);
    $approve=static function(string $action)use($db,$org,$approvalRepo,$reviewer,$actionService,$assert):void{
        $id=(string)$db->fetchOne('SELECT id FROM cos_approvals WHERE organization_id=? AND action_id=?',[$org,$action]);
        $assert($id!==''&&$approvalRepo->decide($org,$id,ApprovalStatus::Approved,$reviewer,'Independent CI approval'),'Scheduled approval missing.');
        $actionService->queue($org,$action);
    };
    for($page=0;$page<2;$page++){
        $assert($tick()==='PLAN_PROPOSED','Schedule did not propose its pinned Plan.');
        $pid=$scheduleRow()['plan_id'];
        $assert($tick()==='PLAN_APPROVAL_REQUESTED'&&$tick()==='WAIT_PLAN_APPROVAL','Schedule bypassed Plan approval.');
        $planAction=$goals->planApprovalAction($actor,$pid)['id'];
        $assert($actionService->execute($org,$planAction,'schedule-unapproved-plan')===null,'Unapproved scheduled Plan executed.');
        $approve($planAction);
        $planResult=$actionService->executeOutcome($org,$planAction,'schedule-plan');
        $assert($planResult!==null&&$planResult->result->successful,'Scheduled Plan approval handler failed.');
        $assert($tick()==='RUN_STARTED','Schedule did not start canonical run.');
        $assert($tick()==='RUN_ADVANCED'&&$tick()==='RUN_ADVANCED','Schedule did not submit sweep Action.');
        $key=(string)$db->fetchOne('SELECT s.idempotency_key FROM cos_workflow_steps s JOIN cos_workflow_runs w ON w.organization_id=s.organization_id AND w.run_id=s.run_id WHERE w.organization_id=? AND w.source_plan_id=?',[$org,$pid]);
        $action=(string)$db->fetchOne('SELECT id FROM cos_actions WHERE organization_id=? AND idempotency_key=?',[$org,'wf:'.$key]);
        $assert($action!==''&&$actionService->execute($org,$action,'schedule-unapproved-sweep')===null,'Scheduled sweep bypassed approval.');
        $assert(count($captureStore->list($org,$campaignId,$date,$date,501,0))===($page===0?0:50),'Unapproved scheduled sweep changed capture.');
        $approve($action);
        $result=$actionService->executeOutcome($org,$action,'schedule-sweep');
        $assert($result!==null&&$result->result->successful&&$result->result->data['scanned']===($page===0?50:1),'Scheduled page sweep failed.');
        $assert($tick()==='RUN_ADVANCED'&&$tick()==='RUN_ADVANCED','Schedule did not attest and complete workflow.');
        $assert($tick()===($page===0?'CONTINUATION_READY':'CYCLE_SCANNED'),'Schedule did not checkpoint verified native receipt.');
        $row=$scheduleRow();
        $assert($row['plan_id']===null&&$row['window_start']===$date&&$row['window_end']===$date
            &&($page===0?$row['after_cursor']!==null:$row['after_cursor']===null),'Schedule cursor/window persistence failed.');
    }
    $assert(count($captureStore->list($org,$campaignId,$date,$date,501,0))===51&&$tick()==='CURRENT_CYCLE_SCANNED','Schedule did not exhaust exactly 51 candidates.');
    $deny(fn()=>$scheduler->tick($actor,array_replace($profile,['max_pages'=>2]),$clock),'Changed schedule profile reused checkpoint.');
    $assert($scheduler->tick($actor,$profile,new DateTimeImmutable('2026-02-05T00:00:00Z'))==='PLAN_PROPOSED'
        &&$scheduleRow()['after_cursor']===null,'Next daily cycle did not restart from null cursor.');
    echo "Marketing M4 scheduled two-page canonical Plan/Action approvals, durable continuation, rolling rescan PASS.\n";

    // M5.9: no mocked Plan approval. The same canonical coordinator issues an independent Plan Action.
    $optCap=\Domains\Marketing\Automation\Action\MarketingOptimizationReviewHandler::TYPE;
    $optCriterion=\App\Persistence\Marketing\MarketingOptimizationReviewOutcomeProvider::CRITERION;
    $pdo->prepare('INSERT INTO cos_policies
        (id,organization_id,code,name,action_type,conditions,decision,priority,version,status)
        VALUES (?,?,"mkt-opt-review-e2e","Independent optimization review approval",?,?,"APPROVAL_REQUIRED",1,1,"ACTIVE")')
        ->execute([bin2hex(random_bytes(16)),$org,$optCap,'[]']);
    $optAccount=$optAccounts->registerAccount($org,'env://META_OPT_FIXTURE',['ad_account_id'=>'act_'.random_int(1000000000,9999999999),
        'currency'=>'USD','timezone'=>'UTC','connection_status'=>'READ_ONLY','capability_scope'=>['ads_read']]);
    $mediaRow=static fn(string $from,string $to,string $spend,string $clicks):array=>[
        'account_id'=>substr($optAccount['ad_account_id'],4),'campaign_id'=>'90001','date_start'=>$from,'date_stop'=>$to,
        'spend'=>$spend,'impressions'=>'2000','clicks'=>$clicks];
    $batch=static fn(string $key,string $from,string $to,string $spend,string $clicks):array=>$optMediaStore->commit(
        $org,$owner,'opt-'.$key.'-'.$token,hash('sha256',$key),$optAccount,'campaign',$from,$to,[$mediaRow($from,$to,$spend,$clicks)]);
    $optBaseline=$batch('base','2026-03-01','2026-03-07','100.00','100');
    $optCurrent=$batch('current','2026-03-08','2026-03-14','150.00','40');
    $optParameters=['external_id'=>'90001','rule'=>'CTR_DECLINE','threshold_percent'=>60,
        'current_run_id'=>$optCurrent['id'],'baseline_run_id'=>$optBaseline['id'],'window_start'=>'2026-03-08','window_end'=>'2026-03-14',
        'baseline_start'=>'2026-03-01','baseline_end'=>'2026-03-07'];
    $optEval=$ruleService->evaluate($actor,['account_id'=>$optAccount['id'],...$optParameters]);
    $assert($optEval['status']==='TRIGGERED_REVIEW_REQUIRED','Native rule fixture did not trigger.');
    $optInput=['target_type'=>'marketing_paid_account','target_id'=>$optAccount['id'],
        'parameters'=>[...$optParameters,'evaluation_hash'=>$optEval['evaluation_hash']]];
    $optPlanner=new \App\Persistence\Marketing\MarketingOptimizationReviewPlanProposalService($db,$goals,$bridge,$optReviewService);
    $optAdapter=new \App\Application\Capability\MarketingOptimizationReviewPlanProposeAdapter($optPlanner);
    $optKey='opt-plan-'.$token;
    $deny(fn()=>$optAdapter->invoke($actor,[...$optInput,'parameters'=>[...$optInput['parameters'],'evaluation_hash'=>str_repeat('0',64)]],$optKey,'fixture'),
        'Forged rule evaluation proposed a Plan.');
    $optProposal=$optAdapter->invoke($actor,$optInput,$optKey,'fixture');
    $assert($optProposal['state']==='proposed'&&$optAdapter->invoke($actor,$optInput,$optKey,'fixture')['state']==='existing',
        'Optimization Plan replay failed.');
    $optRun='run-opt-'.$token;$optPlan=$optProposal['plan_id'];
    $countReviews=static fn():int=>(int)$db->fetchOne('SELECT COUNT(*) FROM marketing_optimization_receipts WHERE organization_id=?',[$org]);
    $deny(fn()=>$bridge->start($actor,$optRun,$optPlan,bin2hex(random_bytes(16))),'Unapproved optimization Plan started.');
    $assert($countReviews()===0,'Plan proposal recorded a review.');
    $optPlanAction=$coordinator->requestApproval($actor,$optPlan,'opt-plan-'.$token)['action_id'];
    $assert($actionService->execute($org,$optPlanAction,'opt-plan-unapproved')===null,'Unapproved optimization Plan Action executed.');
    $approve($optPlanAction);
    $assert($actionService->execute($org,$optPlanAction,'opt-plan-approved')?->status===ActionStatus::Completed,'Optimization Plan approval failed.');
    $bridge->start($actor,$optRun,$optPlan,$optPlanAction);
    $bridge->advance($actor,$optRun,$optPlanAction);
    $optPending=$bridge->advance($actor,$optRun,$optPlanAction);
    $assert($optPending['state']==='awaiting_human_approval'&&$countReviews()===0,'Optimization record bypassed independent Action approval.');
    $optAction=$optPending['action_id'];
    $assert($actionService->execute($org,$optAction,'opt-action-unapproved')===null,'Unapproved optimization Action executed.');
    $approve($optAction);
    $optResult=$actionService->executeOutcome($org,$optAction,'opt-action-approved');
    $assert($optResult!==null&&$optResult->result->successful&&$optResult->result->data['review_recorded']
        &&!$optResult->result->data['effect_confirmed']&&!$optResult->result->data['provider_mutation']&&$countReviews()===1,
        'Approved optimization review did not persist independent native receipt.');
    $assert($actionService->execute($org,$optAction,'opt-action-replay')===null,'Optimization Action replayed.');
    $assert($bridge->advance($actor,$optRun,$optPlanAction)['state']==='step_completed'&&$bridge->advance($actor,$optRun,$optPlanAction)['state']==='completed',
        'Optimization review canonical receipt not attested.');
    $optOutcome=$goals->recordEvaluation($actor,'opt-eval-'.$token,$optRun);
    $assert(($optOutcome['result']??null)==='satisfied'&&($optOutcome['criteria'][0]['observed']??null)===1
        &&$optReviewOutcome->verifiedReceipt($actor,$optRun)===$optResult->result->data,
        'Optimization review Goal not independently satisfied.');
    $optReceiptId=$optResult->result->data['review_id'];
    // Duplicate signal in another independently approved plan may not append another receipt.
    $duplicate=$optAdapter->invoke($actor,$optInput,'opt-duplicate-'.$token,'fixture');
    $duplicatePlan=$duplicate['plan_id'];$duplicateRun='run-opt-dup-'.$token;
    $duplicateApproval=$coordinator->requestApproval($actor,$duplicatePlan,'opt-dup-'.$token)['action_id'];$approve($duplicateApproval);
    $assert($actionService->execute($org,$duplicateApproval,'opt-dup-plan')?->status===ActionStatus::Completed,'Duplicate fixture Plan approval failed.');
    $bridge->start($actor,$duplicateRun,$duplicatePlan,$duplicateApproval);$bridge->advance($actor,$duplicateRun,$duplicateApproval);
    $duplicateAction=$bridge->advance($actor,$duplicateRun,$duplicateApproval)['action_id'];$approve($duplicateAction);
    $assert($actionService->execute($org,$duplicateAction,'opt-dup-action')?->status===ActionStatus::Failed&&$countReviews()===1,'Duplicate signal recorded twice.');
    // A newer batch between approvals and worker readback invalidates the originally approved evidence.
    $staleBaseline=$batch('stale-base','2026-04-01','2026-04-07','100.00','100');
    $staleCurrent=$batch('stale-current','2026-04-08','2026-04-14','150.00','40');
    $staleParams=[...$optParameters,'current_run_id'=>$staleCurrent['id'],'baseline_run_id'=>$staleBaseline['id'],
        'window_start'=>'2026-04-08','window_end'=>'2026-04-14','baseline_start'=>'2026-04-01','baseline_end'=>'2026-04-07'];
    $staleEval=$ruleService->evaluate($actor,['account_id'=>$optAccount['id'],...$staleParams]);
    $staleProposal=$optAdapter->invoke($actor,[...$optInput,'parameters'=>[...$staleParams,'evaluation_hash'=>$staleEval['evaluation_hash']]],'opt-stale-'.$token,'fixture');
    $stalePlan=$staleProposal['plan_id'];$staleRun='run-opt-stale-'.$token;
    $staleApproval=$coordinator->requestApproval($actor,$stalePlan,'opt-stale-'.$token)['action_id'];$approve($staleApproval);
    $assert($actionService->execute($org,$staleApproval,'opt-stale-plan')?->status===ActionStatus::Completed,'Stale fixture Plan approval failed.');
    $bridge->start($actor,$staleRun,$stalePlan,$staleApproval);$bridge->advance($actor,$staleRun,$staleApproval);
    $staleAction=$bridge->advance($actor,$staleRun,$staleApproval)['action_id'];$approve($staleAction);
    $batch('stale-revision','2026-04-08','2026-04-14','150.00','95');
    $assert($actionService->execute($org,$staleAction,'opt-stale-action')?->status===ActionStatus::Failed&&$countReviews()===1,
        'Stale optimization signal passed worker recheck.');

    // M5.10: independent subsequent observation. Historical approved review does not imply causal lift.
    $learnCap=\Domains\Marketing\Automation\Action\MarketingOptimizationLearningHandler::TYPE;
    $learnCriterion=\App\Persistence\Marketing\MarketingOptimizationLearningOutcomeProvider::CRITERION;
    $pdo->prepare('INSERT INTO cos_policies
        (id,organization_id,code,name,action_type,conditions,decision,priority,version,status)
        VALUES (?,?,"mkt-opt-learn-e2e","Independent optimization learning approval",?,?,"APPROVAL_REQUIRED",1,1,"ACTIVE")')
        ->execute([bin2hex(random_bytes(16)),$org,$learnCap,'[]']);
    $follow=$batch('followup','2026-03-15','2026-03-21','150.00','80');
    $learnParameters=['review_run_id'=>$optRun,'review_evaluation_id'=>'opt-eval-'.$token,'review_id'=>$optReceiptId,
        'followup_run_id'=>$follow['id'],'followup_window_start'=>'2026-03-15','followup_window_end'=>'2026-03-21'];
    $learnInput=['target_type'=>'marketing_paid_account','target_id'=>$optAccount['id'],'parameters'=>$learnParameters];
    $learnPreview=new \App\Application\Capability\MarketingOptimizationLearningPreviewAdapter($learningService);
    $learnPlanner=new \App\Persistence\Marketing\MarketingOptimizationLearningPlanProposalService($db,$goals,$bridge,$learningService);
    $learnAdapter=new \App\Application\Capability\MarketingOptimizationLearningPlanProposeAdapter($learnPlanner);
    $learnGet=new \App\Application\Capability\MarketingOptimizationLearningGetAdapter($learningService);
    $deniedEval=[...$learnInput,'parameters'=>[...$learnParameters,'review_evaluation_id'=>'forged-'.$token]];
    $deny(fn()=>$learnPreview->invoke($actor,$deniedEval,'fixture','fixture'),'Learning without trusted recorded review outcome admitted.');
    $deny(fn()=>$learnPreview->invoke($actor,[...$learnInput,'target_id'=>'mads-'.str_repeat('0',24)],'fixture','fixture'),'Learning foreign account admitted.');
    $deny(fn()=>$learnPreview->invoke($actor,[...$learnInput,'parameters'=>[...$learnParameters,'followup_run_id'=>$optCurrent['id'],
        'followup_window_start'=>'2026-03-08','followup_window_end'=>'2026-03-14']],'fixture','fixture'),'Overlapping learning window admitted.');
    // An absent follow-up row is never a successful learning record.
    $empty=$optMediaStore->commit($org,$owner,'opt-empty-'.$token,hash('sha256','empty'),$optAccount,'campaign','2026-03-29','2026-04-04',[]);
    $unavailable=$learnPreview->invoke($actor,[...$learnInput,'parameters'=>[...$learnParameters,'followup_run_id'=>$empty['id'],
        'followup_window_start'=>'2026-03-29','followup_window_end'=>'2026-04-04']],'fixture','fixture');
    // Use non-overlapping exact windows even when other imported scopes exist.
    $assert($unavailable['status']==='INSUFFICIENT_EVIDENCE'&&$unavailable['next_step']==='COLLECT_EVIDENCE'&&!$unavailable['effect_confirmed'],
        'Absent follow-up promoted to learning effect.');
    // Review approvals may expire after an independently recorded outcome; that history is not live execution authority.
    $oldExpiry=$db->fetchAllAssociative('SELECT id,expires_at FROM cos_approvals WHERE organization_id=? AND action_id IN (?,?)',[$org,$optAction,$optPlanAction]);
    $db->executeStatement("UPDATE cos_approvals SET expires_at='2020-01-01 00:00:00' WHERE organization_id=? AND action_id IN (?,?)",[$org,$optAction,$optPlanAction]);
    $learn=$learnPreview->invoke($actor,$learnInput,'fixture','fixture');
    $assert($learn['status']==='IMPROVED_OBSERVED'&&$learn['window_relation']==='RETROSPECTIVE_ONLY'
        &&(float)$learn['followup_evaluation']['change_percent']===100.0&&!$learn['causal_attribution']&&!$learn['rule_change_authorized'],
        'Follow-up learning overclaimed causality or lost direction.');
    $learnActionInput=[...$learnInput,'parameters'=>[...$learnParameters,'learning_hash'=>$learn['learning_hash']]];
    $learnKey='opt-learning-'.$token;$lproposal=$learnAdapter->invoke($actor,$learnActionInput,$learnKey,'fixture');
    $assert($lproposal['state']==='proposed'&&$learnAdapter->invoke($actor,$learnActionInput,$learnKey,'fixture')['state']==='existing'&&$countReviews()===1,
        'Learning proposal replay or premature write.');
    $lplan=$lproposal['plan_id'];$lrun='run-opt-learn-'.$token;
    $lplanAction=$coordinator->requestApproval($actor,$lplan,'opt-learning-'.$token)['action_id'];
    $assert($actionService->execute($org,$lplanAction,'learning-plan-unapproved')===null,'Learning Plan bypassed approval.');
    $approve($lplanAction);
    $assert($actionService->execute($org,$lplanAction,'learning-plan-approved')?->status===ActionStatus::Completed,'Learning Plan approval failed.');
    $bridge->start($actor,$lrun,$lplan,$lplanAction);$bridge->advance($actor,$lrun,$lplanAction);
    $lpending=$bridge->advance($actor,$lrun,$lplanAction);
    $assert($lpending['state']==='awaiting_human_approval'&&$countReviews()===1,'Learning skipped independent Action approval.');
    $laction=$lpending['action_id'];
    $assert($actionService->execute($org,$laction,'learning-unapproved')===null,'Unapproved learning Action executed.');
    $approve($laction);$lresult=$actionService->executeOutcome($org,$laction,'learning-approved');
    $assert($lresult!==null&&$lresult->result->successful&&$lresult->result->data['learning_recorded']&&$countReviews()===2
        &&!$lresult->result->data['effect_confirmed']&&!$lresult->result->data['rule_change_authorized'],'Approved learning native readback failed.');
    $lid=$lresult->result->data['learning_id'];
    $readback=$learnGet->invoke($actor,['learning_id'=>$lid],'fixture','fixture');
    $assert($readback===$lresult->result->data&&$actionService->execute($org,$laction,'learning-replay')===null,'Learning replay or receipt lookup failed.');
    $assert($bridge->advance($actor,$lrun,$lplanAction)['state']==='step_completed'&&$bridge->advance($actor,$lrun,$lplanAction)['state']==='completed',
        'Learning canonical Run did not complete.');
    $lout=$goals->recordEvaluation($actor,'opt-learn-eval-'.$token,$lrun);
    $assert(($lout['result']??null)==='satisfied'&&($lout['criteria'][0]['observed']??null)===1
        &&$learningOutcome->verifiedReceipt($actor,$lrun)===$readback,'Learning Goal not independently satisfied.');
    foreach($oldExpiry as $approvalRow)$db->executeStatement('UPDATE cos_approvals SET expires_at=? WHERE organization_id=? AND id=?',[$approvalRow['expires_at'],$org,$approvalRow['id']]);
    $db->executeStatement('UPDATE marketing_optimization_receipts SET request_hash=? WHERE organization_id=? AND id=?',[str_repeat('0',64),$org,$lid]);
    $deny(fn()=>$learningOutcome->observeRun($actor,$lrun,$learnCriterion,new DateTimeImmutable('-1 day'),new DateTimeImmutable('+1 day')),
        'Tampered learning receipt accepted as outcome.');
    echo "Marketing M5.10 historical trusted review, pinned retrospective follow-up, independent Plan and Action, native learning receipt and Outcome PASS.\n";
    $db->executeStatement('UPDATE marketing_optimization_receipts SET request_hash=? WHERE organization_id=? AND id=?',[str_repeat('0',64),$org,$optReceiptId]);
    $deny(fn()=>$optReviewOutcome->observeRun($actor,$optRun,$optCriterion,new DateTimeImmutable('-1 day'),new DateTimeImmutable('+1 day')),
        'Tampered optimization receipt accepted as trusted outcome.');
    echo "Marketing M5.9 real canonical Plan and Action approvals, native optimization review, stale and duplicate fences and independent Outcome PASS.\n";

    // M5.1–M5.2 scheduled monitor: exact native batches, automatic proposal, independent approvals and Outcome.
    $monitorClock=new DateTimeImmutable('today',new DateTimeZone('UTC'));
    $monitorWindows=\Domains\Marketing\Domain\MarketingOptimizationSchedulePolicy::windows($monitorClock,7);
    $batch('schedule-baseline',$monitorWindows['baseline_start'],$monitorWindows['baseline_end'],'100.00','100');
    $batch('schedule-current',$monitorWindows['window_start'],$monitorWindows['window_end'],'100.00','40');
    $monitorProfile=['profile_id'=>'monitor-e2e','organization_id'=>$org,'actor_id'=>$owner,'account_id'=>$optAccount['id'],
        'external_id'=>'90001','rule'=>'CTR_DECLINE','threshold_percent'=>60,'lookback_days'=>7,'followup_enabled'=>true];
    $monitorScheduler=new \App\Persistence\Marketing\MarketingOptimizationScheduleService($db,$goals,$bridge,$coordinator,$optPlanner,$optMedia,$ruleService,$modules,$optReviewOutcome,$learningService,$learnPlanner);
    $tickMonitor=static fn()=>$monitorScheduler->tick($actor,$monitorProfile,$monitorClock);
    $monitorRow=static fn()=>$db->fetchAssociative('SELECT * FROM marketing_optimization_schedules WHERE organization_id=? AND profile_id=?',[$org,$monitorProfile['profile_id']]);
    $beforeMonitor=$countReviews();
    $assert($tickMonitor()==='PLAN_PROPOSED'&&$countReviews()===$beforeMonitor,'Monitor failed to propose or wrote without approval.');
    $mpid=$monitorRow()['plan_id'];
    $assert($tickMonitor()==='PLAN_APPROVAL_REQUESTED'&&$tickMonitor()==='WAIT_PLAN_APPROVAL','Monitoring skipped Plan approval.');
    $mapproval=$goals->planApprovalAction($actor,$mpid)['id'];
    $assert($actionService->execute($org,$mapproval,'monitor-unapproved-plan')===null,'Unapproved monitor Plan executed.');
    $approve($mapproval);
    $assert($actionService->execute($org,$mapproval,'monitor-plan-approved')?->status===ActionStatus::Completed,'Monitoring Plan approval failed.');
    $assert($tickMonitor()==='RUN_STARTED'&&$tickMonitor()==='RUN_ADVANCED'&&$tickMonitor()==='RUN_ADVANCED','Monitor did not submit canonical review Action.');
    $mkey=(string)$db->fetchOne('SELECT s.idempotency_key FROM cos_workflow_steps s JOIN cos_workflow_runs w ON w.organization_id=s.organization_id AND w.run_id=s.run_id WHERE w.organization_id=? AND w.source_plan_id=?',[$org,$mpid]);
    $maction=(string)$db->fetchOne('SELECT id FROM cos_actions WHERE organization_id=? AND idempotency_key=?',[$org,'wf:'.$mkey]);
    $assert($maction!==''&&$actionService->execute($org,$maction,'monitor-unapproved-action')===null&&$countReviews()===$beforeMonitor,'Monitoring bypassed Action approval.');
    $approve($maction);
    $assert($actionService->execute($org,$maction,'monitor-action-approved')?->status===ActionStatus::Completed,'Monitor review Action failed.');
    $assert($tickMonitor()==='RUN_ADVANCED'&&$tickMonitor()==='RUN_ADVANCED'&&$tickMonitor()==='REVIEW_RECORDED','Monitor did not reach trusted Outcome.');
    $mr=$monitorRow();
    $assert($mr['state']==='REVIEW_RECORDED'&&$mr['evaluation_id']!==null&&$countReviews()===$beforeMonitor+1
        &&$tickMonitor()==='WAIT_FOLLOWUP_WINDOW','Monitor receipt checkpoint or prospective learning wait failed.');
    $deny(fn()=>$monitorScheduler->tick($actor,[...$monitorProfile,'threshold_percent'=>50],$monitorClock),'Changed monitor configuration reused checkpoint.');
    $quiet=[...$monitorProfile,'profile_id'=>'monitor-quiet','rule'=>'CPM_INCREASE','threshold_percent'=>25,'followup_enabled'=>false];
    $assert($monitorScheduler->tick($actor,$quiet,$monitorClock)==='NOT_TRIGGERED'&&$monitorScheduler->tick($actor,$quiet,$monitorClock)==='CURRENT_CYCLE_OBSERVED','Quiet monitor proposed an unnecessary Action.');
    // Native trusted scheduled review is eligible for a separately approved pause only for the
    // exact current activated paid plan; no provider call is made by this eligibility check.
    $pauseAudience=$planning->saveAudience($org,$owner,null,null,$planningPolicy->audience([
        'name'=>'Optimization pause fixture','target_spec'=>['market_countries'=>['UA'],'audience_hypothesis'=>'Fixture review']
    ]),'pause-fixture-audience-'.$token);
    $pauseAdset=$planning->saveAdset($org,$campaignId,1,$owner,null,null,$planningPolicy->adset([
        'name'=>'Optimization pause fixture','audience_id'=>$pauseAudience['id'],'audience_revision'=>1,
        'creatives'=>[['creative_id'=>$creativeId,'revision'=>1]],'placements'=>['AUTOMATIC'],'optimization'=>'TRAFFIC',
        'planned_daily_budget'=>'12.50','currency'=>'USD'
    ]),'pause-fixture-adset-'.$token);
    $pauseFrom=$monitorWindows['baseline_start'];$pauseTo=$monitorClock->modify('+7 days')->format('Y-m-d');
    $pauseStore=new \Domains\Marketing\Infrastructure\Persistence\MySql\MysqlMarketingPaidLaunchPlanStore($pdo);
    $pausePlan=$pauseStore->create($org,$owner,'pause-fixture-plan-'.$token,hash('sha256','pause-fixture-plan-'.$token),[
        'campaign_id'=>$campaignId,'campaign_revision'=>1,'adset_id'=>$pauseAdset['id'],'adset_revision'=>1,'adset_content_hash'=>$pauseAdset['content_hash'],
        'account_id'=>$optAccount['id'],'account_revision'=>1,'currency'=>'USD','account_timezone'=>'UTC','period_start'=>$pauseFrom,'period_end'=>$pauseTo,
        ...\Domains\Marketing\Domain\MarketingPaidFundingPolicy::budget('12.50','USD',$pauseFrom,$pauseTo)]);
    $pauseJournal=new \Domains\Marketing\Infrastructure\Persistence\MySql\MysqlMarketingPaidOperationJournal($pdo);
    foreach(['DEPLOY_PAUSED'=>['CREATE_CAMPAIGN','CREATE_ADSET','CREATE_AD'],'ACTIVATE'=>['ACTIVATE_AD','ACTIVATE_ADSET','ACTIVATE_CAMPAIGN']] as $op=>$steps){
        $action=bin2hex(random_bytes(16));$pauseJournal->beginCommand($org,$pausePlan['id'],$op,$action,hash('sha256',$action.':'.$op));
        foreach($steps as $step){
            $remote=str_ends_with($step,'CAMPAIGN')?'90001':(str_ends_with($step,'ADSET')?'90002':'90003');
            $pauseJournal->begin($org,$pausePlan['id'],$step,$action,hash('sha256',$action.':'.$step));
            $pauseJournal->identify($org,$pausePlan['id'],$step,$action,$remote);
            $pauseJournal->finish($org,$pausePlan['id'],$step,$action,['provider_id'=>$remote,'configured_status'=>$op==='ACTIVATE'?'ACTIVE':'PAUSED']);
        }
        $pauseJournal->identify($org,$pausePlan['id'],$op,$action,'90001');
        $pauseJournal->finish($org,$pausePlan['id'],$op,$action,['plan_id'=>$pausePlan['id'],'operation'=>$op,
            'provider_campaign_id'=>'90001','state'=>$op==='ACTIVATE'?'ACTIVE_CONFIGURED':'PAUSED','spend_confirmed'=>false]);
    }
    $nativeReview=$optReviewOutcome->verifiedReceipt($actor,$mr['run_id']);
    $reviewPin=['review_run_id'=>$mr['run_id'],'review_evaluation_id'=>$mr['evaluation_id'],
        'review_id'=>$nativeReview['review_id'],'review_receipt_hash'=>$nativeReview['receipt_hash']];
    $pauseGuard=new \Domains\Marketing\Application\Service\MarketingOptimizationPauseGuard($optHistory,$ruleService,$sweepPlans,$pauseJournal);
    $pauseGuard->verify($actor,$pausePlan['id'],$reviewPin);
    $deny(fn()=>$pauseGuard->verify($actor,$pausePlan['id'],[...$reviewPin,'review_receipt_hash'=>str_repeat('0',64)]),'Changed trusted review pin accepted for pause.');
    $batch('schedule-revised',$monitorWindows['window_start'],$monitorWindows['window_end'],'100.00','90');
    $deny(fn()=>$pauseGuard->verify($actor,$pausePlan['id'],$reviewPin),'Stale review remained eligible after native provider batch revision.');
    $assert($pauseJournal->get($org,$pausePlan['id'],'PAUSE')===null,'Review eligibility caused a provider operation.');
    echo "Marketing M5 native trusted review to current activated paid-plan pause eligibility and stale-source fence PASS.\n";
    // Missing batches exercise actual provider-read import before checkpoint transaction.
    $freshProvider=new class implements \Domains\Marketing\Application\Contract\PaidMediaProviderInterface{
        public int $calls=0;
        public function inspectAccount(string $organizationId,string $providerAccountId,string $credentialRef):array{throw new DomainException('Unexpected account probe.');}
        public function hierarchy(string $organizationId,string $providerAccountId,string $credentialRef):array{throw new DomainException('Unexpected hierarchy probe.');}
        public function insights(string $organizationId,string $providerAccountId,string $credentialRef,string $from,string $to,string $grain):array{
            $this->calls++;
            return [['account_id'=>substr($providerAccountId,4),'campaign_id'=>'90001','date_start'=>$from,'date_stop'=>$to,
                'spend'=>'100.00','impressions'=>'2000','clicks'=>'100']];
        }
    };
    $freshMedia=new \Domains\Marketing\Application\Service\MarketingMediaAnalyticsService($optAccounts,$freshProvider,$optMediaStore,$modules);
    $freshRules=new \Domains\Marketing\Application\Service\MarketingOptimizationRuleService($freshMedia);
    $freshMonitor=new \App\Persistence\Marketing\MarketingOptimizationScheduleService($db,$goals,$bridge,$coordinator,$optPlanner,$freshMedia,$freshRules,$modules);
    $freshProfile=[...$monitorProfile,'profile_id'=>'monitor-import','rule'=>'CPM_INCREASE','threshold_percent'=>25,'followup_enabled'=>false];
    $freshClock=new DateTimeImmutable('2026-06-15T00:00:00Z');
    $assert($freshMonitor->tick($actor,$freshProfile,$freshClock)==='NOT_TRIGGERED'&&$freshProvider->calls===2
        &&$freshMonitor->tick($actor,$freshProfile,$freshClock)==='CURRENT_CYCLE_OBSERVED'&&$freshProvider->calls===2,
        'Missing monitoring batches were not imported once outside the schedule transaction.');
    echo "Marketing M5 scheduled native monitoring, one UTC cycle, independent canonical Plan and Action, Outcome and quiet signals PASS.\n";


} finally {
    if($oldRollout===false)putenv('COS_FEDERATION_CANONICAL_ORGANIZATION');
    else putenv('COS_FEDERATION_CANONICAL_ORGANIZATION='.$oldRollout);
}
