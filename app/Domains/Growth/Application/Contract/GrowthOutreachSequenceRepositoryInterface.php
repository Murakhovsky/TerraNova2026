<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

interface GrowthOutreachSequenceRepositoryInterface
{
    /** @return array<string,mixed>|null */
    public function latestProfile(string $organizationId):?array;
    /** @param array<string,mixed> $profile */
    public function appendProfile(array $profile):void;
    /** @return list<string> */
    public function schedulerOrganizations(int $limit):array;
    /** @return list<array<string,mixed>> */
    public function initialCandidates(string $organizationId,int $limit):array;

    /** @param array<string,mixed> $sequence */
    public function createSequence(array $sequence):void;
    /** @return array<string,mixed>|null */
    public function sequenceByRootRecommendation(string $organizationId,string $rootRecommendationId):?array;
    /** @return array<string,mixed>|null */
    public function sequenceForRecommendation(string $organizationId,string $recommendationId):?array;
    /** @return array<string,mixed>|null */
    public function latestForCandidate(string $organizationId,string $candidateId):?array;
    /** @return list<array<string,mixed>> */
    public function activeSequences(string $organizationId,int $limit):array;
    /** @return array<string,mixed> */
    public function lockSequence(string $organizationId,string $sequenceId):array;
    public function sequenceStatusForRecommendation(string $organizationId,string $recommendationId):?string;

    /** @param array<string,mixed> $step */
    public function appendStep(array $step):void;
    /** @return list<array<string,mixed>> */
    public function steps(string $organizationId,string $sequenceId):array;
    /** @return array<string,mixed>|null */
    public function latestStep(string $organizationId,string $sequenceId):?array;

    public function scheduleDue(string $organizationId,string $sequenceId,string $nextDueAt):void;
    public function advance(string $organizationId,string $sequenceId,int $touchCount,string $recommendationId):void;
    public function finish(string $organizationId,string $sequenceId,string $status,string $code,string $reason):void;
}
