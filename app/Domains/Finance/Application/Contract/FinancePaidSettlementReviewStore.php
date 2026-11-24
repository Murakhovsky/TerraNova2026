<?php
declare(strict_types=1);
namespace Domains\Finance\Application\Contract;
interface FinancePaidSettlementReviewStore
{
    public function record(string $org,array $review):array;
    public function find(string $org,string $reservationId,string $documentRef,string $versionRef,string $snapshotRef,int $revision):?array;
}
