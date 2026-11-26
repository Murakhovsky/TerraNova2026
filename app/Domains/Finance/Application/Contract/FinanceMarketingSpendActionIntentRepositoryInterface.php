<?php
declare(strict_types=1);
namespace Domains\Finance\Application\Contract;

/**
 * Finance-owned durable key/fingerprint gate for canonical approved Actions.
 * Claim is recorded before crossing the Finance evidence write boundary.
 */
interface FinanceMarketingSpendActionIntentRepositoryInterface
{
    /** @return array{state:string,finance_observation_id:?string} */
    public function claim(string $org,string $key,string $account,string $snapshot,int $revision,string $sourceHash):array;
    public function complete(string $org,string $key,string $observationId,int $revision,string $sourceHash):void;
}
