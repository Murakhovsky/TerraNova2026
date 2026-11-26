<?php
declare(strict_types=1);
namespace Domains\Marketing\Application\Contract;

use Kernel\Tenant\Model\TenantContext;

/** Historical independently recorded Federation outcome; cannot create or approve a Run. */
interface MarketingOptimizationReviewEvidenceReadPort
{
    public function review(TenantContext $actor,string $runId,string $evaluationId,string $reviewId):array;
}
