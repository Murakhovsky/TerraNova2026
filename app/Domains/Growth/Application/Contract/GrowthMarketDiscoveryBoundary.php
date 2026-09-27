<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

interface GrowthMarketDiscoveryBoundary
{
    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function createUniverse(string $organizationId,int $actorId,string $correlationId,string $idempotencyKey,array $input):array;

    /** @return array<string,mixed> */
    public function setEnabled(string $organizationId,int $actorId,string $correlationId,string $universeId,bool $enabled,string $idempotencyKey):array;

    /** @return array<string,mixed> */
    public function runUniverse(string $organizationId,int $actorId,string $correlationId,string $universeId,string $idempotencyKey,int $limit=100):array;

    /** @return list<array<string,mixed>> */
    public function universes(string $organizationId):array;

    /** @return array<string,mixed> */
    public function universeBrief(string $organizationId,string $universeId):array;

    /** Called by durable Signal consumer; only account Signals can materialize an opportunity. */
    public function considerSignal(string $organizationId,string $accountId,string $signalId,string $correlationId):void;
}
