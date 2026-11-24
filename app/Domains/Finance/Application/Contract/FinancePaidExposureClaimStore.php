<?php
declare(strict_types=1);
namespace Domains\Finance\Application\Contract;

/** Internal Finance owner port. A fresh claim consumes execution authority, not money. */
interface FinancePaidExposureClaimStore
{
    public function claimExposure(string $org,string $envelopeId,string $reservationId,string $actor,string $action,
        string $trace,array $input):array;
    public function exposure(string $org,string $reservationId):?array;
}
