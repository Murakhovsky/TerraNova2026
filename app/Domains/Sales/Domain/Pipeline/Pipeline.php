<?php
declare(strict_types=1);

namespace Domains\Sales\Domain\Pipeline;

use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final readonly class Pipeline
{
    /** @param list<PipelineStage> $stages */
    public function __construct(
        public PipelineId $id,
        public OrganizationId $organizationId,
        public string $code,
        public string $name,
        public array $stages,
        public PipelineStageId $initialStageId,
    ) {
        if (trim($code) === '' || trim($name) === '') {
            throw new InvalidArgumentException('Pipeline code and name are required.');
        }
        if ($stages === []) {
            throw new InvalidArgumentException('Pipeline requires stages.');
        }

        $codes = [];
        $won = 0;
        $lost = 0;
        $initial = null;
        foreach ($stages as $stage) {
            if (!$stage instanceof PipelineStage) {
                throw new InvalidArgumentException('Pipeline contains an invalid stage.');
            }
            if (isset($codes[$stage->code])) {
                throw new InvalidArgumentException('Pipeline stage codes must be unique.');
            }
            $codes[$stage->code] = true;
            $won += $stage->isWon ? 1 : 0;
            $lost += $stage->isLost ? 1 : 0;
            if ($stage->id->value() === $initialStageId->value()) {
                $initial = $stage;
            }
        }
        if ($won !== 1 || $lost !== 1) {
            throw new InvalidArgumentException('Pipeline requires exactly one won and one lost stage.');
        }
        if ($initial === null || $initial->isTerminal) {
            throw new InvalidArgumentException('Pipeline initial stage must exist and be non-terminal.');
        }
    }

    public function stage(PipelineStageId $id): ?PipelineStage
    {
        foreach ($this->stages as $stage) {
            if ($stage->id->value() === $id->value()) {
                return $stage;
            }
        }
        return null;
    }

    public function initialStage(): PipelineStage
    {
        return $this->stage($this->initialStageId)
            ?? throw new InvalidArgumentException('Pipeline initial stage is unavailable.');
    }
}
