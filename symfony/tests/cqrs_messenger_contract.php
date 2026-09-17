<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Infrastructure\Messenger\SymfonyCommandBus;
use App\Infrastructure\Messenger\SymfonyEventBus;
use App\Infrastructure\Messenger\SymfonyQueryBus;
use Kernel\Application\Command\CommandHandlerInterface;
use Kernel\Application\Command\CommandInterface;
use Kernel\Application\Event\EventHandlerInterface;
use Kernel\Application\Event\EventInterface;
use Kernel\Application\Query\QueryHandlerInterface;
use Kernel\Application\Query\QueryInterface;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;

function expectCqrs(bool $condition, string $message): void
{
    if (!$condition) {
        throw new \RuntimeException($message);
    }
}

final readonly class MessengerProbeCommand implements CommandInterface
{
    public function __construct(public string $value)
    {
    }
}

final class MessengerProbeCommandHandler implements CommandHandlerInterface
{
    public function __invoke(MessengerProbeCommand $command): string
    {
        return 'command:' . $command->value;
    }
}

final class MessengerSecondCommandHandler implements CommandHandlerInterface
{
    public function __invoke(MessengerProbeCommand $command): string
    {
        return 'second-command:' . $command->value;
    }
}

final readonly class MessengerProbeQuery implements QueryInterface
{
    public function __construct(public string $value)
    {
    }
}

final class MessengerProbeQueryHandler implements QueryHandlerInterface
{
    public function __invoke(MessengerProbeQuery $query): string
    {
        return 'query:' . $query->value;
    }
}

final readonly class MessengerProbeEvent implements EventInterface
{
    public function __construct(public string $value)
    {
    }
}

final class MessengerFirstEventHandler implements EventHandlerInterface
{
    public int $calls = 0;

    public function __invoke(MessengerProbeEvent $event): void
    {
        ++$this->calls;
    }
}

final class MessengerSecondEventHandler implements EventHandlerInterface
{
    public int $calls = 0;

    public function __invoke(MessengerProbeEvent $event): void
    {
        ++$this->calls;
    }
}

$commandHandler = new MessengerProbeCommandHandler();
$commandMessenger = new MessageBus([
    new HandleMessageMiddleware(new HandlersLocator([
        MessengerProbeCommand::class => [$commandHandler],
    ])),
]);
$commandBus = new SymfonyCommandBus($commandMessenger);
expectCqrs($commandBus->dispatch(new MessengerProbeCommand('ok')) === 'command:ok', 'Command bus must return the single handler result.');

$queryHandler = new MessengerProbeQueryHandler();
$queryMessenger = new MessageBus([
    new HandleMessageMiddleware(new HandlersLocator([
        MessengerProbeQuery::class => [$queryHandler],
    ])),
]);
$queryBus = new SymfonyQueryBus($queryMessenger);
expectCqrs($queryBus->ask(new MessengerProbeQuery('ok')) === 'query:ok', 'Query bus must return the single handler result.');

$firstEventHandler = new MessengerFirstEventHandler();
$secondEventHandler = new MessengerSecondEventHandler();
$eventMessenger = new MessageBus([
    new HandleMessageMiddleware(new HandlersLocator([
        MessengerProbeEvent::class => [$firstEventHandler, $secondEventHandler],
    ]), true),
]);
$eventBus = new SymfonyEventBus($eventMessenger);
$eventBus->publish(new MessengerProbeEvent('ok'));
expectCqrs($firstEventHandler->calls === 1 && $secondEventHandler->calls === 1, 'Event bus must fan out to every registered handler.');

$noHandlerEventBus = new SymfonyEventBus(new MessageBus([
    new HandleMessageMiddleware(new HandlersLocator([]), true),
]));
$noHandlerEventBus->publish(new MessengerProbeEvent('no-handler'));

$duplicateCommandBus = new SymfonyCommandBus(new MessageBus([
    new HandleMessageMiddleware(new HandlersLocator([
        MessengerProbeCommand::class => [new MessengerProbeCommandHandler(), new MessengerSecondCommandHandler()],
    ])),
]));
try {
    $duplicateCommandBus->dispatch(new MessengerProbeCommand('duplicate'));
    throw new \RuntimeException('Command bus must reject multiple handler results.');
} catch (\LogicException) {
}

echo "Symfony Messenger CQRS contract passed.\n";
