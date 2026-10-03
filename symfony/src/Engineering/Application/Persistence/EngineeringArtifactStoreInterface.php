<?php
declare(strict_types=1);

namespace App\Engineering\Application\Persistence;

use App\Engineering\Domain\Artifact\ArtifactType;

interface EngineeringArtifactStoreInterface
{
    /** @return array{id:string,type:string,version:int,status:string,content:array,content_hash:string} */
    public function createVersion(
        string $featureId,
        ArtifactType $type,
        array $content,
        ?string $taskId = null,
        ?string $agentRunId = null,
        ?string $createdByAgent = null,
    ): array;

    /** @return array{id:string,type:string,version:int,status:string,content:array,content_hash:string}|null */
    public function latest(string $featureId, ArtifactType $type): ?array;
}
