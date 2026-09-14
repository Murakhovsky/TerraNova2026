<?php
declare(strict_types=1);

namespace Bootstrap;

use Domains\Sales\Application\Contract\InboundCaseResolverInterface;
use Domains\Sales\Application\Service\SalesInboundService;

final readonly class InboundCaseResolverAdapter implements InboundCaseResolverInterface
{
    public function __construct(private SalesInboundService $inbound) {}

    public function resolvePropertyId(mixed $value): ?int
    {
        return $this->inbound->resolvePropertyId($value);
    }

    public function resolvePersonAndCase(array $input): array
    {
        $result = $this->inbound->ensureCase($input);
        return [
            'person_id' => $result->data['person_id'] ?? null,
            'client_case_id' => $result->data['client_case_id'] ?? null,
        ];
    }

    public function attachRequest(int $caseId, int $requestId): void
    {
        $this->inbound->registerRequest($caseId, $requestId);
    }
}
