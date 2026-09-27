<?php
declare(strict_types=1);
namespace Domains\Growth\Application\Contract;
interface GrowthOutreachSequenceBoundary
{
    public function viewPolicy(string $organizationId):array;
    public function updatePolicy(string $organizationId,int $actorId,string $correlationId,string $idempotencyKey,array $input):array;
    public function sequenceBrief(string $organizationId,string $candidateId):array;
    public function stopSequence(string $organizationId,int $actorId,string $correlationId,string $candidateId,string $sequenceId,string $reason,string $idempotencyKey):array;
    public function runOrganization(string $organizationId,int $actorId,string $correlationId,string $triggerKey):array;
}
