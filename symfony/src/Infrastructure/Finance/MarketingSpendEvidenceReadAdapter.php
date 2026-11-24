<?php
declare(strict_types=1);
namespace App\Infrastructure\Finance;

use Domains\Finance\Application\Contract\MarketingSpendEvidenceReadPort;
use Domains\Marketing\Application\Service\MarketingPaidReadService;
use Kernel\Tenant\Model\TenantContext;

/**
 * Cross-domain adapter lives outside Finance and Marketing business code.
 * Marketing owns the read query, Finance owns the resulting ledger record.
 */
final readonly class MarketingSpendEvidenceReadAdapter implements MarketingSpendEvidenceReadPort
{
    public function __construct(private MarketingPaidReadService $marketing){}
    public function get(TenantContext $actor,string $accountId,string $snapshotId):array
    {
        return $this->marketing->spendEvidence($actor,$accountId,$snapshotId);
    }
}
