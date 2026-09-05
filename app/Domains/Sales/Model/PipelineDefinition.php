<?php
declare(strict_types=1);

namespace Domains\Sales\Model;

use DomainException;

final readonly class PipelineDefinition
{
    /** @param list<PipelineStageDefinition> $stages */
    public function __construct(
        public string $id,
        public string $organizationId,
        public string $code,
        public string $name,
        public array $stages,
    ) {
        if ($id === '' || $organizationId === '' || $code === '' || $name === '') {
            throw new DomainException('Pipeline identity, organization, code and name are required.');
        }
        $codes = array_map(static fn (PipelineStageDefinition $stage): string => $stage->code, $stages);
        if ($stages === [] || count($codes) !== count(array_unique($codes))) {
            throw new DomainException('Pipeline requires uniquely coded stages.');
        }
        if (count(array_filter($stages, static fn (PipelineStageDefinition $stage): bool => $stage->isWon)) !== 1) {
            throw new DomainException('Pipeline requires exactly one won stage.');
        }
        if (count(array_filter($stages, static fn (PipelineStageDefinition $stage): bool => $stage->isLost)) !== 1) {
            throw new DomainException('Pipeline requires exactly one lost stage.');
        }
    }
}
