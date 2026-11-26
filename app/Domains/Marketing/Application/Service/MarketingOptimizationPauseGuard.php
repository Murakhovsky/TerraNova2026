<?php
declare(strict_types=1);
namespace Domains\Marketing\Application\Service;

use DomainException;
use Domains\Marketing\Application\Contract\MarketingOptimizationReviewEvidenceReadPort;
use Domains\Marketing\Domain\MarketingExperimentPolicy;
use Domains\Marketing\Domain\MarketingOptimizationPausePolicy;
use Domains\Marketing\Infrastructure\Persistence\MySql\MysqlMarketingPaidOperationJournal;
use Kernel\Tenant\Model\TenantContext;

/** Bind an approved review to an exact active native campaign before a separately approved pause. */
final readonly class MarketingOptimizationPauseGuard
{
    public function __construct(private MarketingOptimizationReviewEvidenceReadPort $history,private MarketingOptimizationRuleService $rules,
        private MarketingPaidLaunchPlanService $plans,private MysqlMarketingPaidOperationJournal $journal){}
    public function verify(TenantContext $actor,string $planId,array $pin):void
    {
        MarketingOptimizationPausePolicy::pin($pin);
        $source=$this->history->review($actor,$pin['review_run_id'],$pin['review_evaluation_id'],$pin['review_id']);
        if(!hash_equals($source['review_receipt_hash'],$pin['review_receipt_hash']))throw new DomainException('Optimization review receipt changed.');
        $old=$source['review']['evaluation'];
        $current=$this->rules->evaluate($actor,array_intersect_key($old,array_flip(['account_id','external_id','rule','threshold_percent',
            'current_run_id','baseline_run_id','window_start','window_end','baseline_start','baseline_end'])));
        if(MarketingExperimentPolicy::digest($old)!==MarketingExperimentPolicy::digest($current))
            throw new DomainException('Optimization evidence changed after review.');
        $org=$actor->organizationId()->value();$rows=[];
        foreach(MysqlMarketingPaidOperationJournal::OPERATIONS as $operation)$rows[$operation]=$this->journal->get($org,$planId,$operation);
        MarketingOptimizationPausePolicy::verify($this->plans->get($actor,$planId),$current,$rows,gmdate('Y-m-d'));
    }
}
