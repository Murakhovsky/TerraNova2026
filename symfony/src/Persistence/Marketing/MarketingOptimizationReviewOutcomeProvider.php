<?php
declare(strict_types=1);
namespace App\Persistence\Marketing;

use App\Persistence\Federation\FederationOutcomeSourceNotReady;
use App\Persistence\Workflow\DoctrineWorkflowActionReceiptVerifier;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use DomainException;
use Domains\Marketing\Application\Contract\MarketingOptimizationReceiptStore;
use Domains\Marketing\Application\Service\MarketingOptimizationReviewService;
use Domains\Marketing\Automation\Action\MarketingOptimizationReviewHandler;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Platform\Orchestration\Goal\RunScopedGoalOutcomeEvidenceProviderInterface;
use Platform\Workflow\Model\WorkflowRunIdentity;

final readonly class MarketingOptimizationReviewOutcomeProvider implements RunScopedGoalOutcomeEvidenceProviderInterface
{
    public const CRITERION='marketing.optimization.review_recorded';
    public function __construct(private Connection $db,private MarketingOptimizationReceiptStore $native,
        private DoctrineWorkflowActionReceiptVerifier $receipts){}
    public function domain():string{return 'marketing';}
    public function supports(string $criterionId):bool{return $criterionId===self::CRITERION;}
    public function observe(string $organizationId,string $criterionId,DateTimeImmutable $from,DateTimeImmutable $to):array
    {throw new FederationOutcomeSourceNotReady('Optimization review requires a linked canonical Workflow Run.');}
    public function observeRun(TenantContext $actor,string $runId,string $criterionId,DateTimeImmutable $from,DateTimeImmutable $to):array
    {
        if(!$this->supports($criterionId)||$to<=$from)throw new DomainException('Invalid review outcome scope.');
        $r=$this->verifiedReceipt($actor,$runId);
        return ['value'=>1,'evidence'=>['marketing:optimization_review:'.$r['review_id'].':'.$r['receipt_hash']],
            'source'=>'marketing.native_optimization.review_recorded.v1','window_start'=>$from->format('Y-m-d\TH:i:s.uP'),
            'window_end'=>$to->format('Y-m-d\TH:i:s.uP'),'attribution'=>'run_linked_action','run_id'=>$runId];
    }
    public function verifiedReceipt(TenantContext $actor,string $runId):array
    {
        if(!$actor->isManager()||!$actor->allows(TenantPermissions::ACCESS)||!$actor->allows(TenantPermissions::MANAGE))
            throw new DomainException('Review receipt access denied.');
        $org=$actor->organizationId()->value();
        $rows=$this->db->fetchAllAssociative('SELECT w.input_digest,w.state,w.actor_id,s.step_id,s.state AS step_state,
            s.idempotency_key,s.result_reference,p.plan_json
            FROM cos_workflow_runs w JOIN cos_workflow_steps s ON s.organization_id=w.organization_id AND s.run_id=w.run_id
            JOIN cos_federation_plans p ON p.organization_id=w.organization_id AND p.plan_id=w.source_plan_id
            WHERE w.organization_id=:org AND BINARY w.organization_id=BINARY :exact_org AND w.run_id=:run AND w.definition_id=:definition AND s.capability_id=:cap',
            ['org'=>$org,'exact_org'=>$org,'run'=>$runId,'definition'=>'federation.approved-plan','cap'=>MarketingOptimizationReviewHandler::TYPE]);
        if(count($rows)!==1||$rows[0]['state']!=='completed'||$rows[0]['step_state']!=='completed'
            ||!preg_match('/^action:([a-f0-9]{32})$/D',(string)$rows[0]['result_reference'],$match))
            throw new FederationOutcomeSourceNotReady('Completed optimization review Action unavailable.');
        $row=$rows[0];
        $proof=$this->receipts->attestForAudit(new WorkflowRunIdentity($org,$runId),(string)$row['step_id'],(string)$row['result_reference'],(string)$row['input_digest']);
        if(!$proof->verified)throw new DomainException('Canonical review receipt unverified.');
        $plan=json_decode((string)$row['plan_json'],true,32,JSON_THROW_ON_ERROR);
        $step=$plan['steps'][0]??null;
        if(count($plan['steps']??[])!==1||!is_array($step)||($step['capability_id']??null)!==MarketingOptimizationReviewHandler::TYPE
            ||!preg_match('/^[a-f0-9]{64}$/D',(string)$row['idempotency_key'])||($step['input']['target_type']??null)!=='marketing_paid_account')
            throw new DomainException('Review outcome requires exactly one pinned canonical Action.');
        $input=$step['input'];$stored=$this->native->byKey($org,'wf:'.$row['idempotency_key']);
        if($stored===null)throw new FederationOutcomeSourceNotReady('Native optimization review receipt unavailable.');
        return MarketingOptimizationReviewService::verify($stored,(string)$row['actor_id'],$input['target_id'],$input['parameters'],$match[1]);
    }
}
