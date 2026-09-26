<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

use Domains\Growth\Domain\QualificationEvaluation;
use Domains\Growth\Domain\QualificationPolicy;

interface GrowthDecisionRepositoryInterface
{
    public function createPolicy(QualificationPolicy $policy,int $actorId): void;
    public function lockPolicy(string $organizationId,string $policyId,int $revision): QualificationPolicy;
    public function updatePolicy(QualificationPolicy $policy,int $actorId): void;
    public function archiveOtherActivePolicyRevisions(string $organizationId,string $policyId,int $exceptRevision,int $actorId): void;
    /** @return array<string,mixed>|null */
    public function viewPolicy(string $organizationId,string $policyId,int $revision): ?array;

    public function createEvaluation(string $evaluationId,string $organizationId,QualificationEvaluation $evaluation,int $actorId): void;
    /** @return array<string,mixed>|null */
    public function viewEvaluation(string $organizationId,string $evaluationId): ?array;
    /** @return array<string,mixed>|null */
    public function latestEvaluation(string $organizationId,string $candidateId): ?array;
}
