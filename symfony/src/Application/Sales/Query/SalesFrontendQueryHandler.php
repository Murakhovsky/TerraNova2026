<?php
declare(strict_types=1);

namespace App\Application\Sales\Query;

use DomainException;
use Domains\Sales\Application\Contract\SalesWorkspaceOperationalReadModelInterface;
use Kernel\Application\Query\QueryHandlerInterface;
use Kernel\Operations\Contract\OperationsReadModelInterface;

final readonly class SalesFrontendQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private SalesWorkspaceOperationalReadModelInterface $workspace,
        private OperationsReadModelInterface $operations,
    ) {
    }

    /** @return array<string,mixed> */
    public function __invoke(SalesFrontendQuery $query): array
    {
        $organizationId = $query->organizationId->value();

        return match ($query->operation) {
            SalesFrontendQuery::SEARCH => $this->workspace->search(
                $organizationId,
                trim((string) ($query->input['q'] ?? '')),
                max(1, min(12, (int) ($query->input['limit'] ?? 6))),
            ),
            SalesFrontendQuery::INTELLIGENCE => $this->intelligence($organizationId, $query->input),
            default => throw new DomainException('Unsupported Sales frontend query.'),
        };
    }

    /** @param array<string,mixed> $input */
    private function intelligence(string $organizationId, array $input): array
    {
        $dealId = (int) ($input['deal_id'] ?? 0);
        if ($dealId <= 0) {
            throw new DomainException('Invalid opportunity id.');
        }

        return $this->operations->dealIntelligence($organizationId, $dealId);
    }
}
