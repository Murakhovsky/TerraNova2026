<?php
declare(strict_types=1);

namespace Domains\Sales\Model;

use DomainException;

final readonly class PipelineDefinition
{
    public string $initialStageId;

    /** @param list<PipelineStageDefinition> $stages */
    public function __construct(
        public string $id,
        public string $organizationId,
        public string $code,
        public string $name,
        public array $stages,
        ?string $initialStageId = null,
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

        // Keep in-memory construction source-compatible with V0.6 tests and callers.
        // Persisted/runtime pipelines are stricter: MysqlPipelineRepository only loads rows
        // with an explicit initial_stage_id, and Admin validation requires it.
        if ($initialStageId === null) {
            foreach ($stages as $stage) {
                if (!$stage->isTerminal) {
                    $initialStageId = $stage->id;
                    break;
                }
            }
        }
        if ($initialStageId === null || !in_array($initialStageId, array_map(static fn (PipelineStageDefinition $stage): string => $stage->id, $stages), true)) {
            throw new DomainException('Pipeline requires an active initial stage.');
        }
        $this->initialStageId = $initialStageId;
        if ($this->initialStage()->isTerminal) {
            throw new DomainException('Pipeline initial stage cannot be terminal.');
        }
    }

    public function initialStage(): PipelineStageDefinition
    {
        foreach ($this->stages as $stage) {
            if ($stage->id === $this->initialStageId) return $stage;
        }
        throw new DomainException('Configured initial stage is unavailable.');
    }
}
