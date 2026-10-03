<?php
declare(strict_types=1);

namespace App\Engineering\Application\Persistence;

use App\Engineering\Application\DTO\EngineeringRequest;

interface EngineeringFeatureStoreInterface
{
    public function create(string $featureId, EngineeringRequest $request, ?string $createdBy = null): void;
    public function request(string $featureId): EngineeringRequest;
    public function updateStatus(string $featureId, string $status): void;
    public function applyManagerAnalysis(string $featureId, array $specification, array $contextMap, ?string $repositoryRevision): void;

    /** @return array<string,mixed> */
    public function view(string $featureId): array;
}
