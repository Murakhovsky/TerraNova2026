<?php
declare(strict_types=1);

namespace Bootstrap;

use Domains\Sales\Application\Contract\InboundCaseResolverInterface;
use Domains\Sales\Application\UseCase\EnsureInboundClientCase;
use Domains\Sales\Application\UseCase\RegisterInboundClientCaseRequest;
use Domains\Sales\Application\UseCase\ResolveInboundProperty;

final readonly class InboundCaseResolverAdapter implements InboundCaseResolverInterface
{
    public function __construct(
        private ResolveInboundProperty $resolveProperty,
        private EnsureInboundClientCase $ensureCase,
        private RegisterInboundClientCaseRequest $registerRequest,
    ) {
    }

    public function resolvePropertyId(mixed $value): ?int
    {
        return $this->resolveProperty->execute($value);
    }

    public function resolvePersonAndCase(array $input): array
    {
        $result = $this->ensureCase->execute($input);
        return [
            'person_id' => $result->data['person_id'] ?? null,
            'client_case_id' => $result->data['client_case_id'] ?? null,
        ];
    }

    public function attachRequest(int $caseId, int $requestId): void
    {
        $this->registerRequest->execute($caseId, $requestId);
    }
}
