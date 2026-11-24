<?php
declare(strict_types=1);
namespace App\Persistence\Finance;

use App\Persistence\Federation\FederationOutcomeSourceNotReady;
use App\Persistence\Workflow\DoctrineWorkflowActionReceiptVerifier;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use DomainException;
use Domains\Finance\Application\Contract\FinancePaidSettlementStore;
use Domains\Finance\Application\Service\ApprovedFinancePaidSettlementService;
use Kernel\Tenant\Model\TenantContext;
use Platform\Orchestration\Goal\RunScopedGoalOutcomeEvidenceProviderInterface;
use Platform\Workflow\Model\WorkflowRunIdentity;

final readonly class FinancePaidSettlementOutcomeProvider implements RunScopedGoalOutcomeEvidenceProviderInterface
{
    public function __construct(private Connection $db,private FinancePaidSettlementStore $settlements,private DoctrineWorkflowActionReceiptVerifier $receipts){}
    public function domain():string{return 'finance';}
    public function supports(string $criterionId):bool{return $criterionId===FinancePaidSettlementGoalProposalService::CRITERION;}
    public function observe(string $organizationId,string $criterionId,DateTimeImmutable $from,DateTimeImmutable $to):array
    {throw new FederationOutcomeSourceNotReady('Settlement outcome requires canonical Run.');}
    public function observeRun(TenantContext $actor,string $runId,string $criterionId,DateTimeImmutable $from,DateTimeImmutable $to):array
    {
        if(!$this->supports($criterionId)||$to<=$from)throw new DomainException('Invalid Finance outcome scope.');
        $org=$actor->organizationId()->value();
        $rows=$this->db->fetchAllAssociative('SELECT w.input_digest,w.state,s.step_id,s.state AS step_state,s.result_reference,s.capability_id,p.plan_json
            FROM cos_workflow_runs w JOIN cos_workflow_steps s ON s.organization_id=w.organization_id AND s.run_id=w.run_id
            JOIN cos_federation_plans p ON p.organization_id=w.organization_id AND p.plan_id=w.source_plan_id
            WHERE w.organization_id=:org AND w.run_id=:run AND w.definition_id=:definition',
            ['org'=>$org,'run'=>$runId,'definition'=>'federation.approved-plan']);
        if(count($rows)!==1||$rows[0]['state']!=='completed'||$rows[0]['step_state']!=='completed')
            throw new FederationOutcomeSourceNotReady('Completed settlement Action required.');
        $row=$rows[0];
        if($row['capability_id']!==ApprovedFinancePaidSettlementService::TYPE||!preg_match('/^action:([a-f0-9]{32})$/D',(string)$row['result_reference'],$match))
            throw new DomainException('Unrelated Action cannot satisfy settlement.');
        $proof=$this->receipts->attestForAudit(new WorkflowRunIdentity($org,$runId),$row['step_id'],$row['result_reference'],$row['input_digest']);
        $steps=json_decode($row['plan_json'],true,32,JSON_THROW_ON_ERROR)['steps']??[];$input=$steps[0]['input']??[];
        if(!$proof->verified||count($steps)!==1||($input['target_type']??null)!=='finance_reservation'||!is_string($input['target_id']??null))
            throw new DomainException('Untrusted settlement Action proof.');
        $r=$this->settlements->receipt($org,$input['target_id']);
        if($r===null||$r['action_id']!==$match[1]||$r['candidate_hash']!==($input['parameters']['candidate_hash']??null)
            ||$r['state']!=='SETTLED'||$r['finance_settled']!==true||$r['expense_confirmed']!==false||$r['payment_confirmed']!==false)
            throw new DomainException('Native settlement receipt differs from approved Action.');
        return ['value'=>1,'evidence'=>['finance:paid_settlement:'.$r['reservation_id']],
            'source'=>'finance.native_paid_budget_settlement.v1','window_start'=>$from->format('Y-m-d\TH:i:s.uP'),
            'window_end'=>$to->format('Y-m-d\TH:i:s.uP'),'attribution'=>'run_linked_action','run_id'=>$runId];
    }
}
