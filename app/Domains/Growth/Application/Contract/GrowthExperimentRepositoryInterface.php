<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

use Domains\Growth\Domain\GrowthExperiment;
use Domains\Growth\Domain\GrowthExperimentAssignment;

interface GrowthExperimentRepositoryInterface
{
    public function createExperiment(GrowthExperiment $experiment,int $actorId):void;
    public function lockExperiment(string $organizationId,string $experimentId):GrowthExperiment;
    public function updateExperiment(GrowthExperiment $experiment,int $actorId):void;

    /** @return array<string,mixed>|null */
    public function viewExperiment(string $organizationId,string $experimentId):?array;

    /** @param array<string,mixed> $filters @return list<array<string,mixed>> */
    public function listExperiments(string $organizationId,array $filters=[],int $limit=100):array;

    public function candidateHasTerminalOutcome(string $organizationId,string $candidateId):bool;

    public function createAssignment(GrowthExperimentAssignment $assignment,int $actorId):void;

    /** @return array<string,mixed>|null */
    public function findAssignment(string $organizationId,string $experimentId,string $candidateId):?array;

    /** @return list<array<string,mixed>> */
    public function assignments(string $organizationId,string $experimentId,int $limit=500):array;

    /** @return array<string,mixed> */
    public function attributionReport(string $organizationId,string $experimentId):array;
}
