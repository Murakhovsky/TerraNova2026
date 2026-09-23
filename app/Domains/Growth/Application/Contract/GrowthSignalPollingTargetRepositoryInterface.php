<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

interface GrowthSignalPollingTargetRepositoryInterface
{
    /**
     * @return list<array{organization_id:string,collectors:list<string>}>
     */
    public function targets(int $organizationLimit=500):array;
}
