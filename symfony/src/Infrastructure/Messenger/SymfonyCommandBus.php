<?php
declare(strict_types=1);

namespace App\Infrastructure\Messenger;

use Kernel\Application\Bus\CommandBusInterface;
use Kernel\Application\Command\CommandInterface;
use LogicException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\SentStamp;

final readonly class SymfonyCommandBus implements CommandBusInterface
{
    public function __construct(private MessageBusInterface $messageBus)
    {
    }

    public function dispatch(CommandInterface $command): mixed
    {
        $envelope = $this->messageBus->dispatch($command);
        $handled = $envelope->all(HandledStamp::class);

        if ($handled === [] && $envelope->all(SentStamp::class) !== []) {
            return null;
        }

        if (count($handled) !== 1) {
            throw new LogicException(sprintf(
                'Command %s must be handled exactly once synchronously or be sent to an async transport; %d handlers returned a result.',
                $command::class,
                count($handled),
            ));
        }

        return $handled[0]->getResult();
    }
}
