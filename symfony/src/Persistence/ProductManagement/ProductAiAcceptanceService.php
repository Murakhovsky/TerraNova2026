<?php
declare(strict_types=1);

namespace App\Persistence\ProductManagement;

use App\Application\Capability\AutomationIntentSignatureVerifier;
use App\Persistence\Capability\CanonicalAutomationIntentStore;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use DomainException;
use Domains\ProductManagement\Application\Service\ProductManagementService;
use Domains\ProductManagement\Infrastructure\Persistence\MySqlStockReconciliationAudit;
use Kernel\Llm\StructuredLlmClientInterface;
use Kernel\Module\ActiveModuleResolver;

/** P6 uses live governed LLM telemetry, existing signed intake and independent native Outcome. No execution/approval authority. */
final readonly class ProductAiAcceptanceService
{
    public function __construct(private Connection $db,private StructuredLlmClientInterface $llm,
        private CanonicalAutomationIntentStore $intents,private AutomationIntentSignatureVerifier $verifier,
        private ActiveModuleResolver $modules,private ProductManagementService $products,
        private ProductGovernedRunOutcomeProvider $outcomes,
        private MySqlStockReconciliationAudit $stock){}

    private function fence(string $org):void
    {
        if(!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,39}$/D',$org)||$org==='default'
            ||$this->verifier->organization()!==$org
            ||$this->db->fetchOne("SELECT status FROM cos_organizations WHERE id=?",[$org])!=='ACTIVE'
            ||!$this->modules->isEnabled($org,'product_management')||!$this->modules->isEnabled($org,'federation'))
            throw new DomainException('Explicit active isolated automation tenant with Product and Federation required.');
    }

    /** No Product, Plan, Workflow or Action is written by this method. */
    public function propose(string $org,string $secret):array
    {
        $this->fence($org);
        if(strlen($secret)<32)throw new DomainException('Signed AI intake is not configured.');
        if((float)$this->db->fetchOne('SELECT monthly_limit FROM cos_llm_budgets WHERE organization_id=? AND currency=?',[$org,'USD'])<0.10)
            throw new DomainException('Explicit governed USD LLM budget required.');
        // Prove the configured secret before spending on an LLM call.
        $nonce=bin2hex(random_bytes(16));$ts=(string)time();$probe='{"probe":true}';
        $this->verifier->verify('ai',$ts,$nonce,self::sign($secret,$ts,$nonce,$probe),$probe);
        $scenario=ProductAiAcceptanceScenario::expected($nonce);
        foreach($scenario['operations'][0]['parameters']['variants'] as $v){
            if($this->products->searchProducts($org,$v['sku'])!==[])throw new DomainException('Canary SKU already exists.');
        }
        $response=$this->llm->complete(ProductAiAcceptanceScenario::request($org,$nonce));
        $payload=ProductAiAcceptanceScenario::validate($response->output,$nonce);
        $usage=$this->usage($org,$nonce);
        if($usage['provider']!==$response->provider||$usage['model']!==$response->model
            ||$usage['provider_request_id']!==$response->providerRequestId)
            throw new DomainException('LLM response is not independently recorded.');
        $raw=json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        $ts=(string)time();
        $intent=$this->intents->submitProduct('ai',$ts,$nonce,self::sign($secret,$ts,$nonce,$raw),$raw);
        if($intent['state']!=='pending_review'||$intent['execution_authority']!=='none')
            throw new DomainException('Canary did not remain review-only.');
        return ['status'=>'PENDING_INDEPENDENT_REVIEW','tenant'=>$org,'intent_id'=>$intent['intent_id'],
            'digest'=>$intent['digest'],'llm_usage_id'=>$usage['id'],'provider'=>$usage['provider'],'model'=>$usage['model'],
            'execution_authority'=>'none','next'=>'human_review_plan_approval_action_approval_workflow_outcome'];
    }

    private static function sign(string $secret,string $ts,string $nonce,string $raw):string
    {return hash_hmac('sha256',implode("\n",['ai',$ts,$nonce,$raw]),$secret);}

    private function usage(string $org,string $nonce):array
    {
        $rows=$this->db->fetchAllAssociative('SELECT id,provider,model,provider_request_id,created_at FROM cos_llm_usage WHERE organization_id=? AND correlation_id=? AND use_case=?',
            [$org,ProductAiAcceptanceScenario::correlation($nonce),ProductAiAcceptanceScenario::USE_CASE]);
        if(count($rows)!==1||!is_string($rows[0]['provider_request_id'])||trim($rows[0]['provider_request_id'])===''
            ||trim((string)$rows[0]['model'])===''||trim((string)$rows[0]['provider'])===''
            ||preg_match('/fixture|mock|stub|fake|test/i',$rows[0]['provider'].' '.$rows[0]['model']))
            throw new DomainException('No uniquely attributable live provider usage.');
        return $rows[0];
    }

    /** Read-only re-attestation; a technical success status is not enough. */
    public function inspect(string $org,string $intentId,string $runId):array
    {
        [$intent,$payload]=$this->loadIntent($org,$intentId,$runId);
        $usage=$this->usage($org,$intent['nonce']);
        // The intent writer stores UTC seconds; usage records retain microseconds.
        if((new DateTimeImmutable($usage['created_at'],new DateTimeZone('UTC')))->getTimestamp()>(new DateTimeImmutable($intent['created_at'],new DateTimeZone('UTC')))->getTimestamp())
            throw new DomainException('LLM receipt postdates intent.');
        $native=$this->verifyNative($org,$intentId,$runId,$intent,$payload);
        return array_replace($native,['status'=>'NATIVE_AI_READBACK_PASS','llm_usage_id'=>$usage['id'],
            'provider'=>$usage['provider'],'model'=>$usage['model']]);
    }

    /** Audit-only native half of the scenario. Never certifies live model provenance. */
    public function inspectNativeForAudit(string $org,string $intentId,string $runId):array
    {
        [$intent,$payload]=$this->loadIntent($org,$intentId,$runId);
        return $this->verifyNative($org,$intentId,$runId,$intent,$payload);
    }

    private function loadIntent(string $org,string $intentId,string $runId):array
    {
        $this->fence($org);
        if(!preg_match('/^intent-[a-f0-9]{24}$/D',$intentId)||!preg_match('/^[a-z0-9][a-z0-9_:-]{0,63}$/D',$runId))
            throw new DomainException('Exact intent and run required.');
        $intent=$this->db->fetchAssociative('SELECT nonce,origin,state,payload_json,request_digest,created_at FROM cos_capability_automation_intents WHERE organization_id=? AND intent_id=?',[$org,$intentId]);
        if(!$intent||$intent['origin']!=='ai'||$intent['state']!=='adopted')throw new DomainException('Adopted signed AI intent required.');
        $payload=ProductAiAcceptanceScenario::validate(json_decode($intent['payload_json'],true,64,JSON_THROW_ON_ERROR),$intent['nonce']);
        return [$intent,$payload];
    }

    private function verifyNative(string $org,string $intentId,string $runId,array $intent,array $payload):array
    {
        if(!hash_equals($intent['request_digest'],ProductAiAcceptanceScenario::digest($payload)))
            throw new DomainException('Signed intent digest drift.');
        $identity=ProductGovernedPlanProposalService::identities($org,ProductGovernedPlanProposalService::intentKey($intentId));
        $run=$this->db->fetchAssociative('SELECT actor_id,state,source_plan_id,definition_json,created_at FROM cos_workflow_runs WHERE organization_id=? AND run_id=? AND definition_id=?',
            [$org,$runId,'federation.approved-plan']);
        if(!$run||$run['state']!=='completed'||$run['source_plan_id']!==$identity['plan_id'])throw new DomainException('Completed canonical intent-linked Run required.');
        $plan=$this->db->fetchAssociative('SELECT goal_id,state,spec_version FROM cos_federation_plans WHERE organization_id=? AND plan_id=?',[$org,$identity['plan_id']]);
        if(!$plan||$plan['state']!=='approved'||$plan['goal_id']!==$identity['goal_id'])
            throw new DomainException('Approved intent-linked Plan required.');
        $steps=json_decode($run['definition_json'],true,64,JSON_THROW_ON_ERROR);
        if(count($steps)!==1||$steps[0]['id']!=='assemble'||$steps[0]['capability_id']!==ProductAiAcceptanceScenario::CAPABILITY)
            throw new DomainException('Unexpected executed graph.');
        $executed=$payload;$executed['operations'][0]['parameters']=$steps[0]['input']['parameters'];
        ProductAiAcceptanceScenario::validate($executed,$intent['nonce']);
        $record=$this->db->fetchAssociative("SELECT evaluation_id,evaluation_json FROM cos_federation_evaluations
            WHERE organization_id=? AND goal_id=? AND spec_version=?
            AND JSON_UNQUOTE(JSON_EXTRACT(evaluation_json,'$.run_id'))=?
            AND JSON_UNQUOTE(JSON_EXTRACT(evaluation_json,'$.evidence_policy'))='domain_read_model_v1'
            ORDER BY evaluated_at DESC,evaluation_id DESC LIMIT 1",[$org,$identity['goal_id'],$plan['spec_version'],$runId]);
        $evaluation=$record?json_decode($record['evaluation_json'],true,64,JSON_THROW_ON_ERROR):[];
        if(($evaluation['result']??null)!=='satisfied'||($evaluation['criteria'][0]['observed']??null)!==1)
            throw new DomainException('Recorded satisfied trusted Goal evaluation required.');
        $native=$this->outcomes->observeRunForAudit($org,$runId,ProductGovernedRunOutcomeProvider::CRITERION,
            new DateTimeImmutable($run['created_at'],new DateTimeZone('UTC')),new DateTimeImmutable('now',new DateTimeZone('UTC')));
        if($native['value']!==1)throw new DomainException('Fresh native Outcome does not match.');
        $stock=$this->stock->audit($org,500);
        if($stock['status']!=='OK')throw new DomainException('Native stock reconciliation incomplete or divergent.');
        return ['status'=>'NATIVE_READBACK_ONLY','tenant'=>$org,'intent_id'=>$intentId,'goal_id'=>$identity['goal_id'],
            'plan_id'=>$identity['plan_id'],'run_id'=>$runId,'evaluation_id'=>$record['evaluation_id'],
            'business_readback'=>$native['evidence'],
            'stock_reconciliation'=>$stock['status'],'independent_release_review_required'=>true];
    }
}
