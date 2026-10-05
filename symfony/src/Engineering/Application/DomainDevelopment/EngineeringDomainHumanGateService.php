<?php
declare(strict_types=1);

namespace App\Engineering\Application\DomainDevelopment;

use App\Engineering\Application\Persistence\EngineeringDomainStoreInterface;
use App\Engineering\Domain\DomainDevelopment\EngineeringDomainStatus;
use RuntimeException;

final readonly class EngineeringDomainHumanGateService
{
    public function __construct(private EngineeringDomainStoreInterface $domains) {}

    /** @param list<array{id:string,description:string}> $options @param array<string,mixed> $evidence */
    public function request(
        string $domainId,
        string $organizationId,
        string $gateType,
        EngineeringDomainStatus $resumeStatus,
        string $question,
        string $reason,
        array $evidence,
        string $requestedBy,
        array $options = [],
    ): string {
        if ($options === []) {
            $options = [
                ['id' => 'APPROVE', 'description' => 'Approve this controlled exception and resume the persisted Domain stage.'],
                ['id' => 'REJECT', 'description' => 'Reject the exception and keep the Domain blocked for rework.'],
            ];
        }
        $id = $this->domains->createHumanDecision(
            $domainId,
            $organizationId,
            $gateType,
            $resumeStatus->value,
            $question,
            $reason,
            $options,
            $evidence,
            $requestedBy,
        );
        $this->domains->updateStatus(
            $domainId,
            EngineeringDomainStatus::HUMAN_APPROVAL->value,
            $gateType.': '.$reason,
        );
        return $id;
    }

    /** @return array<string,mixed> */
    public function answer(
        string $domainId,
        string $decisionId,
        string $selectedOption,
        string $answeredBy,
        ?string $notes = null,
    ): array {
        $decision = $this->domains->answerHumanDecision(
            $domainId,
            $decisionId,
            $selectedOption,
            $answeredBy,
            $notes,
        );
        $selected = strtoupper((string) ($decision['answer']['selected_option'] ?? ''));
        if (in_array($selected, ['APPROVE','CONTINUE'], true) && $this->domains->openHumanDecisions($domainId) !== []) {
            $this->domains->updateStatus(
                $domainId,
                EngineeringDomainStatus::HUMAN_APPROVAL->value,
                'Additional Domain human gates remain open.',
            );
            return $decision;
        }
        if (in_array($selected, ['APPROVE','CONTINUE'], true)) {
            $resume = EngineeringDomainStatus::from((string) $decision['resume_status']);
            $this->domains->updateStatus($domainId, $resume->value, 'Human gate approved by '.$answeredBy.'.');
        } elseif ($selected === 'CANCEL') {
            $this->domains->updateStatus($domainId, EngineeringDomainStatus::CANCELLED->value, 'Human gate cancelled by '.$answeredBy.'.');
        } elseif ($selected === 'REJECT') {
            $this->domains->updateStatus($domainId, EngineeringDomainStatus::BLOCKED->value, 'Human gate rejected by '.$answeredBy.'.');
        } else {
            throw new RuntimeException('Unsupported Domain human decision option '.$selected.'.');
        }

        return $decision;
    }
}
