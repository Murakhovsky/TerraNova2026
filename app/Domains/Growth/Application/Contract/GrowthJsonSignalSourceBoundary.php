<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

interface GrowthJsonSignalSourceBoundary
{
    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function createSource(
        string $organizationId,int $actorId,string $correlationId,string $idempotencyKey,array $input
    ):array;

    /** @return array<string,mixed> */
    public function setEnabled(
        string $organizationId,int $actorId,string $correlationId,string $sourceId,bool $enabled,string $idempotencyKey
    ):array;

    /** @return list<array<string,mixed>> */
    public function sources(string $organizationId):array;
}
