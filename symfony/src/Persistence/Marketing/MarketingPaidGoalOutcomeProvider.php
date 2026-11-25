<?php
declare(strict_types=1);
namespace App\Persistence\Marketing;

use App\Persistence\Federation\FederationOutcomeSourceNotReady;
use App\Persistence\Workflow\DoctrineWorkflowActionReceiptVerifier;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use DomainException;
use Domains\Marketing\Application\Service\MarketingPaidLaunchPlanService;
use Domains\Marketing\Infrastructure\Meta\ApprovedMetaPaidLifecycle;
use Domains\Marketing\Infrastructure\Persistence\MySql\MysqlMarketingPaidOperationJournal;
use Kernel\Tenant\Model\TenantContext;
use Platform\Orchestration\Goal\RunScopedGoalOutcomeEvidenceProviderInterface;
use Platform\Workflow\Model\WorkflowRunIdentity;

/** Historical configured-state receipt only; no current delivery or financial outcome assertion. */
final readonly class MarketingPaidGoalOutcomeProvider implements RunScopedGoalOutcomeEvidenceProviderInterface
{
    public function __construct(private Connection $db,private MarketingPaidLaunchPlanService $plans,
        private MysqlMarketingPaidOperationJournal $journal,private DoctrineWorkflowActionReceiptVerifier $receipts){}
    public function domain():string{return 'marketing';}
    public function supports(string $criterionId):bool{return $criterionId===MarketingPaidGoalProposalService::CRITERION;}
    public function observe(string $organizationId,string $criterionId,DateTimeImmutable $from,DateTimeImmutable $to):array{
        throw new FederationOutcomeSourceNotReady('Paid evidence requires an approved linked Run.');
    }
    public function observeRun(TenantContext $actor,string $runId,string $criterionId,DateTimeImmutable $from,DateTimeImmutable $to):array
    {
        if(!$this->supports($criterionId)||$to<=$from)throw new DomainException('Invalid paid outcome scope.');
        $org=$actor->organizationId()->value();
        $rows=$this->db->fetchAllAssociative('SELECT w.input_digest,w.state,s.step_id,s.state AS step_state,s.result_reference,s.capability_id,p.plan_json
            FROM cos_workflow_runs w JOIN cos_workflow_steps s ON s.organization_id=w.organization_id AND s.run_id=w.run_id
            JOIN cos_federation_plans p ON p.organization_id=w.organization_id AND p.plan_id=w.source_plan_id
            WHERE w.organization_id=:org AND w.run_id=:run AND w.definition_id=:definition',
            ['org'=>$org,'run'=>$runId,'definition'=>'federation.approved-plan']);
        if(count($rows)!==1||$rows[0]['state']!=='completed'||$rows[0]['step_state']!=='completed'
            ||!is_string($rows[0]['result_reference']??null))throw new FederationOutcomeSourceNotReady('Completed paid Action required.');
        $row=$rows[0];$type=$row['capability_id'];
        $op=substr($type,strlen('marketing.paid.meta.campaign.'));
        if(!isset(MarketingPaidGoalProposalService::OPERATIONS[$op])||$type!=='marketing.paid.meta.campaign.'.$op)
            throw new DomainException('Unrelated Action cannot satisfy paid criterion.');
        $proof=$this->receipts->attestForAudit(new WorkflowRunIdentity($org,$runId),$row['step_id'],$row['result_reference'],$row['input_digest']);
        if(!$proof->verified||!preg_match('/^action:([a-f0-9]{32})$/D',$row['result_reference'],$match))throw new DomainException('Untrusted Action receipt.');
        $steps=json_decode($row['plan_json'],true,32,JSON_THROW_ON_ERROR)['steps']??[];
        $input=$steps[0]['input']??[];
        if(count($steps)!==1||($steps[0]['capability_id']??null)!==$type||($input['target_type']??null)!=='marketing_paid_launch_plan'
            ||!is_string($input['target_id']??null)||!is_array($input['parameters']??null))throw new DomainException('Invalid approved paid input.');
        $id=$input['target_id'];$plan=$this->plans->get($actor,$id);$operation=MarketingPaidGoalProposalService::OPERATIONS[$op];
        $receipt=$this->journal->get($org,$id,$operation);$state=$op==='activate'?'ACTIVE_CONFIGURED':'PAUSED';
        if(($input['parameters']['plan_hash']??null)!==$plan['plan_hash']||($receipt['state']??null)!=='CONFIRMED'
            ||$receipt['action_id']!==$match[1]||!hash_equals($receipt['request_hash'],ApprovedMetaPaidLifecycle::commandHash($type,$input['parameters']))
            ||($receipt['receipt']['plan_id']??null)!==$id||($receipt['receipt']['state']??null)!==$state
            ||($receipt['receipt']['operation']??null)!==$operation||($receipt['receipt']['spend_confirmed']??null)!==false
            ||($receipt['receipt']['provider_campaign_id']??null)!==$receipt['provider_id']){
            throw new DomainException('Native paid operation receipt differs from approved Action.');
        }
        return ['value'=>1,'evidence'=>['marketing:paid_operation:'.$id.':'.$operation],
            'source'=>'marketing.native_paid_operation.configured_state.v1','window_start'=>$from->format('Y-m-d\TH:i:s.uP'),
            'window_end'=>$to->format('Y-m-d\TH:i:s.uP'),'attribution'=>'run_linked_action','run_id'=>$runId];
    }
}
