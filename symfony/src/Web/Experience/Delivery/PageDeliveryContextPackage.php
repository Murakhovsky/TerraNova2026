<?php
declare(strict_types=1);

namespace App\Web\Experience\Delivery;

final readonly class PageDeliveryContextPackage
{
    public function __construct(
        public string $pageId,
        public array $contract,
        public array $archetype,
        public array $patterns,
        public array $qualityGate,
        public array $agentConstraints,
    ) {}

    public function toArray(): array
    {
        return [
            'page_id' => $this->pageId,
            'page_contract' => $this->contract,
            'archetype' => $this->archetype,
            'patterns' => $this->patterns,
            'quality_gate' => $this->qualityGate,
            'agent_constraints' => $this->agentConstraints,
        ];
    }
}
