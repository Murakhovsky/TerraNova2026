<?php
declare(strict_types=1);

namespace App\Web\Experience\Adaptive;

use App\Web\Experience\Action\UIAction;

final readonly class ExperienceComposition
{
    /**
     * @param list<string> $visibleSections
     * @param list<string> $collapsedSections
     * @param array<string,string> $disclosureReasons
     * @param list<UIAction> $primaryActions
     * @param list<UIAction> $secondaryActions
     * @param list<array<string,mixed>> $decisionRequests
     * @param list<array<string,mixed>> $blockingRisks
     */
    public function __construct(
        public ExperienceMode $mode,
        public array $visibleSections,
        public array $collapsedSections,
        public array $disclosureReasons,
        public array $primaryActions,
        public array $secondaryActions,
        public array $decisionRequests,
        public array $blockingRisks,
        public string $schemaVersion = '1.0.0',
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'mode' => $this->mode->value,
            'visible_components' => $this->visibleSections,
            'collapsed_components' => $this->collapsedSections,
            'disclosure_reasons' => $this->disclosureReasons,
            'primary_actions' => array_map(static fn (UIAction $a): string => $a->id, $this->primaryActions),
            'secondary_actions' => array_map(static fn (UIAction $a): string => $a->id, $this->secondaryActions),
            'decision_requests' => $this->decisionRequests,
            'blocking_risks' => $this->blockingRisks,
        ];
    }
}
