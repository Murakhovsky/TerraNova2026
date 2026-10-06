<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Contract;

interface TokenizedEquityBacktestRepositoryInterface
{
    /** @param array<string,mixed> $payload */
    public function saveRun(string $organizationId,string $runId,string $hypothesis,string $status,string $datasetHash,array $payload):void;

    /** @return list<array<string,mixed>> */
    public function listRuns(string $organizationId,int $limit=50):array;
}
