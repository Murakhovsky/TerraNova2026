<?php
declare(strict_types=1);
namespace App\Infrastructure\Marketing;

use Domains\Federation\Application\Contract\FederationTrustedActionOutcomeReadPort;
use App\Persistence\Marketing\MarketingOptimizationReviewOutcomeProvider;
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
    public function __construct(private FederationTrustedActionOutcomeReadPort $federation,private MarketingOptimizationReceiptStore $native){}
    public function review(TenantContext $actor,string $runId,string $evaluationId,string $reviewId):array
    {
        if(!$actor->isManager()||!$actor->allows(TenantPermissions::ACCESS)||!$actor->allows(TenantPermissions::MANAGE)
            ||!preg_match('/^[a-z0-9][a-z0-9_:-]{0,63}$/D',$runId)||!preg_match('/^[a-z0-9][a-z0-9_:-]{0,63}$/D',$evaluationId)
            ||!preg_match('/^mkor-[a-f0-9]{24}$/D',$reviewId))throw new DomainException('Historical review access denied.');
        $org=$actor->organizationId()->value();
        $row=$this->federation->read($actor,$runId,$evaluationId,MarketingOptimizationReviewHandler::TYPE);
        $e=json_decode((string)$row['evaluation_json'],true,32,JSON_THROW_ON_ERROR);
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
        $r=MarketingOptimizationReviewService::verify($native,(string)$row['actor_id'],$step['input']['target_id'],$step['input']['parameters'],$row['action_id']);
        if($r['review_id']!==$reviewId||($criterion['evidence']??null)!==['marketing:optimization_review:'.$reviewId.':'.$r['receipt_hash']]
            ||strcmp((string)$row['evaluated_at'],$r['recorded_at'])<0)
            throw new DomainException('Trusted evaluation and exact native review do not match.');
        return ['review'=>$native['receipt'],'review_receipt_hash'=>$native['receipt_hash'],
            'review_run_id'=>$runId,'review_evaluation_id'=>$evaluationId];
    }
}
