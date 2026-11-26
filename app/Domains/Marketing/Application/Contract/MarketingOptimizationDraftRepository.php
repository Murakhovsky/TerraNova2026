<?php
declare(strict_types=1);
namespace Domains\Marketing\Application\Contract;

/** Atomic local-only replacement creation; no external work inside the transaction. */
interface MarketingOptimizationDraftRepository
{
    public function atomic(string $org,string $account,array $plans,array $snapshots,callable $body):array;
    public function createReplacementAdset(string $org,string $actor,string $campaign,int $revision,array $content,string $key):array;
}
