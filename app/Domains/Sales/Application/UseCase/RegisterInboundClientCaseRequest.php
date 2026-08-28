<?php
declare(strict_types=1);

namespace Domains\Sales\Application\UseCase;

use Domains\Sales\Application\Contract\ClientCaseCommandRepositoryInterface;
use Kernel\Transaction\Contract\TransactionManagerInterface;
use RuntimeException;

final readonly class RegisterInboundClientCaseRequest
{
    public function __construct(
        private ClientCaseCommandRepositoryInterface $commands,
        private TransactionManagerInterface $transactions,
        private string $organizationId,
    ) {
    }

    public function execute(int $caseId, int $requestId): void
    {
        $this->transactions->transactional(function () use ($caseId, $requestId): void {
            if (!$this->commands->registerInboundRequest($this->organizationId, $caseId, $requestId)) {
                throw new RuntimeException('Inbound request could not be registered for the client case.');
            }
        });
    }
}
