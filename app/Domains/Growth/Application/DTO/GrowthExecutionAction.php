<?php
declare(strict_types=1);

namespace Domains\Growth\Application\DTO;

final readonly class GrowthExecutionAction
{
    public function __construct(
        public string $id,
        public string $type,
        public ?string $targetType,
        public ?string $targetId,
        public string $sourceType,
        public string $sourceId,
        public string $executionMode,
        public string $riskLevel,
        public string $status,
        public string $createdAt,
        public ?string $executedAt,
    ) {}

    /** @return array<string,mixed> */
    public function toArray():array
    {
        return [
            'action_id'=>$this->id,'type'=>$this->type,'target_type'=>$this->targetType,'target_id'=>$this->targetId,
            'source_type'=>$this->sourceType,'source_id'=>$this->sourceId,'execution_mode'=>$this->executionMode,
            'risk_level'=>$this->riskLevel,'status'=>$this->status,'created_at'=>$this->createdAt,'executed_at'=>$this->executedAt,
        ];
    }
}
