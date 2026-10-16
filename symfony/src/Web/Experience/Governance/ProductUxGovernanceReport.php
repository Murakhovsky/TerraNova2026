<?php
declare(strict_types=1);

namespace App\Web\Experience\Governance;

final readonly class ProductUxGovernanceReport
{
    /**
     * @param list<string> $missingPersonaReferences
     * @param list<string> $journeyUnknownPersonas
     * @param list<string> $journeyUnknownPages
     * @param list<string> $duplicateTerminologyIds
     * @param list<string> $genericContractCopy
     * @param array<string,int> $priorityCounts
     */
    public function __construct(
        public int $personas,
        public int $journeys,
        public int $terminologyTerms,
        public int $openUxDebt,
        public int $blockingUxDebt,
        public int $openDesignSystemGaps,
        public int $blockingDesignSystemGaps,
        public array $missingPersonaReferences,
        public array $journeyUnknownPersonas,
        public array $journeyUnknownPages,
        public array $duplicateTerminologyIds,
        public array $genericContractCopy,
        public array $priorityCounts,
        public bool $priorityInflated,
    ) {}

    public function isStructurallyReady(): bool
    {
        return $this->personas > 0
            && $this->journeys > 0
            && $this->terminologyTerms > 0
            && $this->missingPersonaReferences === []
            && $this->journeyUnknownPersonas === []
            && $this->journeyUnknownPages === []
            && $this->duplicateTerminologyIds === [];
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'structural_ready' => $this->isStructurallyReady(),
            'personas' => $this->personas,
            'journeys' => $this->journeys,
            'terminology_terms' => $this->terminologyTerms,
            'open_ux_debt' => $this->openUxDebt,
            'blocking_ux_debt' => $this->blockingUxDebt,
            'open_design_system_gaps' => $this->openDesignSystemGaps,
            'blocking_design_system_gaps' => $this->blockingDesignSystemGaps,
            'missing_persona_references' => $this->missingPersonaReferences,
            'journey_unknown_personas' => $this->journeyUnknownPersonas,
            'journey_unknown_pages' => $this->journeyUnknownPages,
            'duplicate_terminology_ids' => $this->duplicateTerminologyIds,
            'generic_contract_copy_count' => count($this->genericContractCopy),
            'generic_contract_copy' => $this->genericContractCopy,
            'priority_counts' => $this->priorityCounts,
            'priority_inflated' => $this->priorityInflated,
        ];
    }
}
