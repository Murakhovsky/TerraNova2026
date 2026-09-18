<?php
declare(strict_types=1);

namespace App\Application\Integration\Command;

use DomainException;
use Domains\Sales\Application\DTO\SendMessageCommand;
use Domains\Sales\Application\Service\SalesOperationService;
use Domains\Sales\Model\CommunicationChannel;
use Kernel\Application\Command\CommandHandlerInterface;

final readonly class SendSalesCommunicationCommandHandler implements CommandHandlerInterface
{
    public function __construct(private SalesOperationService $operations)
    {
    }

    /** @return array<string,mixed> */
    public function __invoke(SendSalesCommunicationCommand $command): array
    {
        if ($command->actorId <= 0 || $command->opportunityId <= 0) {
            throw new DomainException('Invalid communication actor or opportunity.');
        }

        $channel = strtoupper(trim($command->channel));
        if (!in_array($channel, CommunicationChannel::values(), true)) {
            throw new DomainException('Unsupported communication channel.');
        }

        $body = trim($command->body);
        if ($body === '' || mb_strlen($body) > 4000) {
            throw new DomainException('Communication body is required and must not exceed 4000 characters.');
        }

        $key = trim($command->idempotencyKey);
        if ($key === '' || mb_strlen($key) > 191) {
            throw new DomainException('A valid idempotency key is required.');
        }

        $result = $this->operations->sendMessage(
            new SendMessageCommand(
                $command->organizationId->value(),
                (string) $command->opportunityId,
                $channel,
                $body,
                $key,
            ),
            [
                'surface' => 'symfony_v1',
                'correlation_id' => $command->correlationId,
            ],
            'USER',
            (string) $command->actorId,
        );

        if (!$result->successful) {
            throw new DomainException($result->error ?? 'Communication delivery failed.');
        }

        return [
            'external_id' => $result->externalId,
            ...$result->data,
        ];
    }
}
