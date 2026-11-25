<?php
declare(strict_types=1);
namespace App\Persistence\Marketing;

use App\Persistence\Federation\FederationOutcomeSourceNotReady;
use App\Persistence\Workflow\DoctrineWorkflowActionReceiptVerifier;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use DomainException;
use Domains\Marketing\Application\Contract\MarketingAnalystRunStore;
use Kernel\Tenant\Model\TenantContext;
use Platform\Orchestration\Goal\RunScopedGoalOutcomeEvidenceProviderInterface;
use Platform\Workflow\Model\WorkflowRunIdentity;

/** Independent readback of the exact native advisory receipt, never its approval or advertising outcome. */
final readonly class MarketingAnalystRunOutcomeProvider implements RunScopedGoalOutcomeEvidenceProviderInterface
{
    public function __construct(private Connection $db,private MarketingAnalystRunStore $runs,
        private DoctrineWorkflowActionReceiptVerifier $receipts){}
    public function domain():string{return 'marketing';}
    public function supports(string $criterionId):bool{return $criterionId===MarketingAnalystPlanProposalService::CRITERION;}
    public function observe(string $organizationId,string $criterionId,DateTimeImmutable $from,DateTimeImmutable $to):array{
        throw new FederationOutcomeSourceNotReady('Marketing advice requires a linked canonical Workflow Run.');
    }
    public function observeRun(TenantContext $actor,string $runId,string $criterionId,DateTimeImmutable $from,DateTimeImmutable $to):array
    {
        if(!$this->supports($criterionId)||$to<=$from)throw new DomainException('Invalid Marketing outcome criterion or interval.');
        $org=$actor->organizationId()->value();
        $rows=$this->db->fetchAllAssociative('SELECT w.input_digest,w.state,w.actor_id,s.step_id,s.state AS step_state,
            s.idempotency_key,s.result_reference,p.plan_json
            FROM cos_workflow_runs w JOIN cos_workflow_steps s ON s.organization_id=w.organization_id AND s.run_id=w.run_id
            JOIN cos_federation_plans p ON p.organization_id=w.organization_id AND p.plan_id=w.source_plan_id
            WHERE w.organization_id=:org AND w.run_id=:run AND w.definition_id=:definition AND s.capability_id=:cap',
            ['org'=>$org,'run'=>$runId,'definition'=>'federation.approved-plan','cap'=>MarketingAnalystPlanProposalService::CAPABILITY]);
        if(count($rows)!==1||$rows[0]['state']!=='completed'||$rows[0]['step_state']!=='completed'
            ||!is_string($rows[0]['result_reference']??null))throw new FederationOutcomeSourceNotReady('No completed Marketing advisory Action.');
        $row=$rows[0];
        $proof=$this->receipts->attestForAudit(new WorkflowRunIdentity($org,$runId),(string)$row['step_id'],
            (string)$row['result_reference'],(string)$row['input_digest']);
        if(!$proof->verified)throw new DomainException('Marketing Action receipt unverified.');
        $plan=json_decode((string)$row['plan_json'],true,32,JSON_THROW_ON_ERROR);
        $steps=$plan['steps']??null;
        if(!is_array($steps)||count($steps)!==1||($steps[0]['capability_id']??null)!==MarketingAnalystPlanProposalService::CAPABILITY){
            throw new DomainException('Marketing advice outcome requires exactly one approved Action.');
        }
        $input=$steps[0]['input']??null;
        $hash=$input['parameters']['context_hash']??null;
        if(!is_string($hash)||!preg_match('/^[a-f0-9]{64}$/D',$hash)||!preg_match('/^[a-f0-9]{64}$/D',(string)$row['idempotency_key'])){
            throw new DomainException('Missing approved Marketing context.');
        }
        $run=$this->runs->byKey($org,'wf:'.$row['idempotency_key']);
        if($run===null||$run['state']!=='SUCCEEDED'||$run['advice']===null
            ||$run['campaign_id']!==($input['target_id']??null)||$run['requested_by']!==$row['actor_id']
            ||!hash_equals($hash,$run['context_hash'])||($run['advice']['context_hash']??null)!==$hash
            ||($run['advice']['runtime']??null)!=='HERMES_AGENT'||($run['advice']['paid_activation_approved']??null)!==false){
            throw new FederationOutcomeSourceNotReady('Native Marketing advice receipt unavailable or inconsistent.');
        }
        return ['value'=>1,'evidence'=>['marketing:analyst_run:'.$run['id']],
            'source'=>'marketing.native_analyst.advice_recorded.v1','window_start'=>$from->format('Y-m-d\TH:i:s.uP'),
            'window_end'=>$to->format('Y-m-d\TH:i:s.uP'),'attribution'=>'run_linked_action','run_id'=>$runId];
    }
}
