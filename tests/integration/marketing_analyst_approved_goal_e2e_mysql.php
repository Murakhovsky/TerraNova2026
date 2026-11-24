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
        new ModuleDefinition(new ModuleManifest('federation','Federation','1.0.0'),new ModuleContributions())]);
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
    $domains=new DomainModuleRegistry([new \Domains\Marketing\Bootstrap\MarketingDomainModule($handler)]);
    $bindings=new FederationCapabilityBindingResolver($capabilities,$domains,$modules);
    $gate=new ModuleActionExecutionGate($domains,$modules,null,$worker);
    $actionService=new ActionService(new MysqlActionRepository($pdo),new ActionExecutor([$handler],$gate));
    $approvalRepo=new MysqlApprovalRepository($pdo);
    $policies=new MysqlPolicyRepository($pdo);
    $engine=new PolicyEngine(new ConditionEvaluator());
    $policyContexts=new PolicyContextBuilder($domains);
    $policyService=new ActionPolicyService($actionService,$policies,new MysqlPolicyEvaluationRepository($pdo),$approvalRepo,$engine,
        $transactions,null,$domains,$modules,$policyContexts);
    $outcome=new MarketingAnalystRunOutcomeProvider($db,$runs,$receipts);
    $goals=new FederationGoalStore($db,$bindings,new GoalPlanValidator($capabilities),new GoalOutcomeEvaluator(),
        new FederationTrustedOutcomeEvidenceResolver($modules,[$outcome]),$planReader);
    $scheduler=new WorkflowStepScheduler();
    $bridge=new FederationCanonicalWorkflowBridge($db,$store,$scheduler,$goals,$planEvidence,$receipts,
        new FailClosedWorkflowNonActionReceiptEvidence(),$planReader,$capabilities,$domains,$modules,$policies,$engine,$policyContexts,$policyService,
        new DoctrineWorkflowExecutionCoordinator($db,$store,$scheduler,$planEvidence,$receipts));
    $readiness=new HermesAnalystReadinessService($profiles,$budget,$modules);
    $planner=new MarketingAnalystPlanProposalService($db,$goals,$bridge,$contexts,$readiness);
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
} finally {
    if($oldRollout===false)putenv('COS_FEDERATION_CANONICAL_ORGANIZATION');
    else putenv('COS_FEDERATION_CANONICAL_ORGANIZATION='.$oldRollout);
}
