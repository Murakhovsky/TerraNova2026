<?php
declare(strict_types=1);
namespace Domains\Marketing\Application\Contract;

use Kernel\Tenant\Model\TenantContext;

/**
 * Consumer-owned read port for independently recorded Platform Goal/Workflow evidence.
 * The composition persistence reader verifies this evidence against the native Marketing receipt.
 * This is a Platform boundary, not a dependency on another business Domain; no Run/approval authority.
 */
interface MarketingOptimizationReviewEvidenceReadPort
{
    public function review(TenantContext $actor,string $runId,string $evaluationId,string $reviewId):array;
}
