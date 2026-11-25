<?php
declare(strict_types=1);
namespace Domains\Sales\Application\Contract;

/** Accepted commercial terms only; neither realized revenue nor payment/settlement. */
interface SalesCommercialEvidencePort
{
    public function acceptedOffer(string $organizationId,int $dealId):?array;
}
