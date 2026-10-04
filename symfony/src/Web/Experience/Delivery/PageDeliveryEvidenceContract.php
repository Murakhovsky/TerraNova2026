<?php
declare(strict_types=1);

namespace App\Web\Experience\Delivery;

final readonly class PageDeliveryEvidenceContract
{
    public function __construct(
        public string $pageId,
        public array $requiredChecks,
        public array $requiredArtifacts,
        public array $humanGate,
    ) {}

    public function toArray(): array
    {
        return [
            'page_id' => $this->pageId,
            'required_checks' => $this->requiredChecks,
            'required_artifacts' => $this->requiredArtifacts,
            'human_gate' => $this->humanGate,
        ];
    }
}
