<?php
declare(strict_types=1);

namespace App\Engineering\Application\DomainDevelopment;

use App\Engineering\Domain\Agent\AgentRole;

final readonly class EngineeringDomainDocumentationTranslationService
{
    public function __construct(private EngineeringDomainAgentService $agents) {}

    /**
     * @param list<array{audience:string,title:string,content:array<string,mixed>}> $documents
     * @return array<string,mixed>
     */
    public function translate(
        string $domainId,
        string $organizationId,
        string $sourceLocale,
        string $targetLocale,
        array $documents,
        string $correlationId,
    ): array {
        return $this->agents->run(
            $domainId,
            $organizationId,
            AgentRole::DOCUMENTATION,
            'Translate the canonical Domain documentation to '.$targetLocale.' without changing its meaning or technical identifiers.',
            [
                'source_locale' => $sourceLocale,
                'target_locale' => $targetLocale,
                'documents' => $documents,
                'translation_rules' => [
                    'canonical_source_remains_authoritative' => true,
                    'preserve_technical_identifiers' => true,
                    'preserve_code_and_machine_values' => true,
                    'no_new_requirements' => true,
                    'no_architecture_changes' => true,
                    'no_release_claims_without_source_evidence' => true,
                ],
            ],
            $correlationId,
        );
    }
}
