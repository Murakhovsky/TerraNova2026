<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Contract;

interface ResearchReplayAdapterInterface
{
    public function supports(string $hypothesisCode):bool;

    /** @param array<string,mixed> $configuration @return array<string,mixed> */
    public function replay(string $organizationId,string $hypothesisCode,array $configuration):array;
}
