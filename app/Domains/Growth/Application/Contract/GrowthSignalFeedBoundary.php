<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

interface GrowthSignalFeedBoundary
{
    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function createFeed(
        string $organizationId,int $actorId,string $correlationId,string $idempotencyKey,array $input
    ):array;

    /** @return array<string,mixed> */
    public function setEnabled(
        string $organizationId,int $actorId,string $correlationId,string $feedId,bool $enabled,string $idempotencyKey
    ):array;

    /** @return list<array<string,mixed>> */
    public function feeds(string $organizationId):array;
}
