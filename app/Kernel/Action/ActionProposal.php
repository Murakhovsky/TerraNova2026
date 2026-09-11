<?php
declare(strict_types=1);

namespace Kernel\Action;

final readonly class ActionProposal
{
    public function __construct(
        public string $type,
        public ?string $targetType,
        public ?string $targetId,
        public array $parameters,
        public string $sourceType,
        public string $sourceId,
        public string $executionMode,
        public string $riskLevel,
        public string $idempotencyKey,
        /** @var array<string,mixed> */
        public array $policyContext = [],
    ) {
    }
}
