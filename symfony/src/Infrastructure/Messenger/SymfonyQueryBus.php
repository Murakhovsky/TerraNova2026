<?php
declare(strict_types=1);

namespace App\Infrastructure\Messenger;

use Kernel\Application\Bus\QueryBusInterface;
use Kernel\Application\Query\QueryInterface;
use LogicException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final readonly class SymfonyQueryBus implements QueryBusInterface
{
    public function __construct(private MessageBusInterface $messageBus)
    {
    }

    public function ask(QueryInterface $query): mixed
    {
        $envelope = $this->messageBus->dispatch($query);
        $handled = $envelope->all(HandledStamp::class);

        if (count($handled) !== 1) {
            throw new LogicException(sprintf(
                'Query %s must be handled exactly once; %d handlers returned a result.',
                $query::class,
                count($handled),
            ));
        }

        return $handled[0]->getResult();
    }
}
