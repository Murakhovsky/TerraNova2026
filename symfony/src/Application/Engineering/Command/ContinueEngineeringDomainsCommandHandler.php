<?php
declare(strict_types=1);

namespace App\Application\Engineering\Command;

use App\Engineering\Application\DomainDevelopment\EngineeringDomainRuntimeService;
use App\Engineering\Application\Persistence\EngineeringDomainStoreInterface;
use App\Engineering\Domain\DomainDevelopment\EngineeringDomainStatus;
use App\Engineering\Domain\Workflow\EngineeringId;
use Kernel\Application\Command\CommandHandlerInterface;
use Throwable;

final readonly class ContinueEngineeringDomainsCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private EngineeringDomainStoreInterface $domains,
        private EngineeringDomainRuntimeService $runtime,
        private string $organizationId,
        private int $limit = 10,
    ) {}

    public function __invoke(ContinueEngineeringDomainsCommand $command): array
    {
        $rows = $this->domains->domainsForOrganization($this->organizationId, $this->limit);
        $results = [];

        foreach ($rows as $domain) {
            $domainId = (string) ($domain['id'] ?? '');
            $status = (string) ($domain['status'] ?? '');
            if ($domainId === '' || in_array($status, [
                EngineeringDomainStatus::COMPLETED->value,
                EngineeringDomainStatus::FAILED->value,
                EngineeringDomainStatus::CANCELLED->value,
                EngineeringDomainStatus::BLOCKED->value,
                EngineeringDomainStatus::RELEASE_READY->value,
                EngineeringDomainStatus::HUMAN_APPROVAL->value,
            ], true)) continue;

            $correlationId = 'engineering-domain:scheduler:'.$domainId.':'.EngineeringId::generate();
            try {
                $result = match ($status) {
                    EngineeringDomainStatus::DRAFT->value => $this->runtime->plan(
                        $domainId,
                        $this->organizationId,
                        $correlationId,
                    ),
                    EngineeringDomainStatus::READY_FOR_IMPLEMENTATION->value,
                    EngineeringDomainStatus::IMPLEMENTATION->value => $this->runtime->tick(
                        $domainId,
                        $this->organizationId,
                        $correlationId,
                    ),
                    EngineeringDomainStatus::INTEGRATION->value,
                    EngineeringDomainStatus::DOMAIN_QA->value => $this->runtime->verify(
                        $domainId,
                        $this->organizationId,
                        $correlationId,
                    ),
                    default => ['domain' => $domain, 'status' => 'no_automatic_action'],
                };

                $results[] = [
                    'domain_id' => $domainId,
                    'previous_status' => $status,
                    'status' => 'continued',
                    'current_status' => $result['domain']['status'] ?? ($result['status'] ?? null),
                ];
            } catch (Throwable $error) {
                $results[] = [
                    'domain_id' => $domainId,
                    'previous_status' => $status,
                    'status' => 'failed',
                    'error' => mb_substr($error->getMessage(), 0, 500),
                ];
            }
        }

        return [
            'trigger' => trim($command->trigger) !== '' ? trim($command->trigger) : 'scheduler',
            'policy' => 'domain_dependency_driven',
            'candidates' => count($rows),
            'results' => $results,
        ];
    }
}
