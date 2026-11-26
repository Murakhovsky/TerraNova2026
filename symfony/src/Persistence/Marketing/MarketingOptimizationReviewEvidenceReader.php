<?php
declare(strict_types=1);
namespace App\Persistence\Marketing;

use Doctrine\DBAL\Connection;
use DomainException;
use Domains\Marketing\Application\Contract\MarketingOptimizationReceiptStore;
use Domains\Marketing\Application\Contract\MarketingOptimizationReviewEvidenceReadPort;
use Domains\Marketing\Application\Service\MarketingOptimizationReviewService;
use Domains\Marketing\Automation\Action\MarketingOptimizationReviewHandler;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;

/** Reads an already recorded trusted Goal evaluation. Historical review does not grant new execution authority. */
final readonly class MarketingOptimizationReviewEvidenceReader implements MarketingOptimizationReviewEvidenceReadPort
{
    public function __construct(private Connection $db,private MarketingOptimizationReceiptStore $native){}
    public function review(TenantContext $actor,string $runId,string $evaluationId,string $reviewId):array
    {
        if(!$actor->isManager()||!$actor->allows(TenantPermissions::ACCESS)||!$actor->allows(TenantPermissions::MANAGE)
            ||!preg_match('/^[a-z0-9][a-z0-9_:-]{0,63}$/D',$runId)||!preg_match('/^[a-z0-9][a-z0-9_:-]{0,63}$/D',$evaluationId)
            ||!preg_match('/^mkor-[a-f0-9]{24}$/D',$reviewId))throw new DomainException('Historical review access denied.');
        $org=$actor->organizationId()->value();
        $rows=$this->db->fetchAllAssociative("SELECT e.evaluation_json,e.evaluated_at,e.goal_id,e.spec_version,w.actor_id,
            s.idempotency_key,s.result_reference,p.plan_json
            FROM cos_federation_evaluations e
            JOIN cos_workflow_runs w ON w.organization_id=e.organization_id
            JOIN cos_federation_plans p ON p.organization_id=w.organization_id AND p.plan_id=w.source_plan_id
                AND p.goal_id=e.goal_id AND p.spec_version=e.spec_version
            JOIN cos_workflow_steps s ON s.organization_id=w.organization_id AND s.run_id=w.run_id
            JOIN cos_actions a ON a.organization_id=w.organization_id AND a.id=SUBSTRING(s.result_reference,8)
                AND a.idempotency_key=CONCAT('wf:',s.idempotency_key) AND a.type=s.capability_id AND a.status='COMPLETED'
            WHERE e.organization_id=:org AND BINARY e.organization_id=BINARY :exact_org AND e.evaluation_id=:evaluation
                AND e.result='satisfied' AND w.run_id=:run AND w.definition_id='federation.approved-plan' AND w.state='completed'
                AND p.state='approved' AND s.capability_id=:cap AND s.state='completed'
                AND JSON_UNQUOTE(JSON_EXTRACT(e.evaluation_json,'$.run_id'))=:evidence_run",
            ['org'=>$org,'exact_org'=>$org,'evaluation'=>$evaluationId,'run'=>$runId,'cap'=>MarketingOptimizationReviewHandler::TYPE,'evidence_run'=>$runId]);
        if(count($rows)!==1||!preg_match('/^action:([a-f0-9]{32})$/D',(string)$rows[0]['result_reference'],$match)
            ||!preg_match('/^[a-f0-9]{64}$/D',(string)$rows[0]['idempotency_key']))
            throw new DomainException('Persisted trusted review outcome unavailable.');
        $row=$rows[0];$e=json_decode((string)$row['evaluation_json'],true,32,JSON_THROW_ON_ERROR);
        $plan=json_decode((string)$row['plan_json'],true,32,JSON_THROW_ON_ERROR);
        $step=$plan['steps'][0]??null;$criterion=$e['criteria'][0]??null;
        if(count($plan['steps']??[])!==1||($step['capability_id']??null)!==MarketingOptimizationReviewHandler::TYPE
            ||($step['input']['target_type']??null)!=='marketing_paid_account'||count($e['criteria']??[])!==1
            ||($e['organization_id']??null)!==$org||($e['goal_id']??null)!==$row['goal_id']||($e['specification_version']??null)!==(int)$row['spec_version']
            ||($e['run_id']??null)!==$runId||($e['evidence_policy']??null)!=='domain_read_model_v1'||($e['result']??null)!=='satisfied'
            ||($criterion['criterion_id']??null)!==MarketingOptimizationReviewOutcomeProvider::CRITERION
            ||($criterion['expected']??null)!==1||($criterion['observed']??null)!==1||($criterion['result']??null)!=='satisfied'
            ||($criterion['attribution']??null)!=='run_linked_action'||($criterion['source']??null)!=='marketing.native_optimization.review_recorded.v1')
            throw new DomainException('Stored trusted evaluation does not establish a review.');
        $native=$this->native->byKey($org,'wf:'.$row['idempotency_key'])??throw new DomainException('Native review unavailable.');
        $r=MarketingOptimizationReviewService::verify($native,(string)$row['actor_id'],$step['input']['target_id'],$step['input']['parameters'],$match[1]);
        if($r['review_id']!==$reviewId||($criterion['evidence']??null)!==['marketing:optimization_review:'.$reviewId.':'.$r['receipt_hash']]
            ||strcmp((string)$row['evaluated_at'],$r['recorded_at'])<0)
            throw new DomainException('Trusted evaluation and exact native review do not match.');
        return ['review'=>$native['receipt'],'review_receipt_hash'=>$native['receipt_hash'],
            'review_run_id'=>$runId,'review_evaluation_id'=>$evaluationId];
    }
}
