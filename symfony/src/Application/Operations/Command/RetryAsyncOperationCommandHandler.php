<?php

declare(strict_types=1);

namespace App\Application\Operations\Command;

use DomainException;
use Kernel\Application\Command\CommandHandlerInterface;
use Kernel\Queue\Contract\AsyncOperationReadModelInterface;
use Kernel\Queue\Contract\JobQueueInterface;

final readonly class RetryAsyncOperationCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private AsyncOperationReadModelInterface $operations,
        private JobQueueInterface $queue,
    ) {
    }

    /** @return array{status:string,operation_id:string} */
    public function __invoke(RetryAsyncOperationCommand $command): array
    {
        if ($command->organizationId === '' || $command->actorId === '') {
            throw new DomainException('Authenticated actor is invalid.');
        }

        if (!preg_match('/^[a-f0-9]{32}$/', $command->operationId)) {
            throw new DomainException('Invalid async operation id.');
        }

        $operation = $this->operations->find($command->organizationId, $command->operationId);
        if ($operation === null) {
            throw new DomainException('Async operation not found.');
        }

        if (!$operation->canRetry) {
            throw new DomainException('Async operation is not eligible for manual retry.');
        }

        $replayed = $this->queue->replayDead($command->organizationId, $command->operationId);
        if ($replayed !== 1) {
            throw new DomainException('Async operation retry lost its concurrency race.');
        }

        return [
            'status' => 'queued',
            'operation_id' => $command->operationId,
        ];
    }
}
