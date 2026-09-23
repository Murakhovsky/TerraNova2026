<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

interface GrowthSignalPollingTargetRepositoryInterface
{
    /**
     * @return list<array{organization_id:string,collectors:list<string>}>
     */
    public function targets(int $organizationLimit=500):array;

    /**
     * @return array{organization_id:string,collectors:list<string>,source_counts:array<string,int>}
     */
    public function targetForOrganization(string $organizationId):array;
}
