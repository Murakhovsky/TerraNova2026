<?php
declare(strict_types=1);
namespace Domains\ProductManagement\Application\Contract;

/** Read-only, tenant-bound provider allocation evidence. Never a reservation or stock authority. */
interface CommerceOrderFulfillmentEvidencePort
{
    public function fulfillmentEvidence(string $org,string $channel,string $connection,string $externalId):array;
}
