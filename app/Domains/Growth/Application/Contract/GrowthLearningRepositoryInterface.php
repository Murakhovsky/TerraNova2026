<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

use Domains\Growth\Domain\GrowthOutcomeObservation;

interface GrowthLearningRepositoryInterface
{
    public function bindExternalSubject(
        string $organizationId,string $candidateId,string $sourceDomain,string $referenceType,string $referenceId,
        string $sourceEventId
    ):void;

    public function candidateByExternalSubject(
        string $organizationId,string $sourceDomain,string $referenceType,string $referenceId
    ):?string;

    /** @return list<string> */
    public function externalSubjectsForCandidate(
        string $organizationId,string $candidateId,string $sourceDomain,string $referenceType
    ):array;

    public function recordOutcome(GrowthOutcomeObservation $outcome):void;

    /** @return list<array<string,mixed>> */
    public function outcomesForCandidate(string $organizationId,string $candidateId,int $limit=100):array;

    /** @return array<string,mixed> */
    public function outcomeSummary(string $organizationId,string $candidateId):array;
}
