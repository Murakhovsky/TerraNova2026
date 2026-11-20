<?php
declare(strict_types=1);
namespace App\Infrastructure\Finance;

use Domains\Finance\Application\Contract\ApprovedMarketingSpendEvidenceReadPort;
use Domains\Marketing\Application\Contract\MarketingPaidReadRepository;

/**
 * Cross-domain anti-corruption adapter, available only to Finance's approved
 * Action service. The source is read through the Marketing-owned repository.
 */
final readonly class ApprovedMarketingSpendEvidenceReadAdapter implements ApprovedMarketingSpendEvidenceReadPort
{
    public function __construct(private MarketingPaidReadRepository $marketing){}
    public function getForApprovedAction(string $organizationId,string $adAccountRef,string $sourceSnapshotRef):array
    {
        return $this->marketing->spendEvidence($organizationId,$adAccountRef,$sourceSnapshotRef);
    }
}
