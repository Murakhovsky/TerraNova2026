<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Kernel\Application\Bus\CommandBusInterface;
use Kernel\Application\Bus\EventBusInterface;
use Kernel\Application\Bus\QueryBusInterface;
use Kernel\Application\Command\CommandHandlerInterface;
use Kernel\Application\Command\CommandInterface;
use Kernel\Application\Event\EventHandlerInterface;
use Kernel\Application\Event\EventInterface;
use Kernel\Application\Query\QueryHandlerInterface;
use Kernel\Application\Query\QueryInterface;

function expectApplication(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final readonly class CreateLeadCommand implements CommandInterface
{
    public function __construct(public string $email)
    {
    }
}

final class CreateLeadHandler implements CommandHandlerInterface
{
    public function __invoke(CreateLeadCommand $command): string
    {
        return 'lead:' . $command->email;
    }
}

final readonly class GetLeadQuery implements QueryInterface
{
    public function __construct(public string $id)
    {
    }
}

final class GetLeadHandler implements QueryHandlerInterface
{
    public function __invoke(GetLeadQuery $query): array
    {
        return ['id' => $query->id];
    }
}

final readonly class LeadCreated implements EventInterface
{
    public function __construct(public string $leadId)
    {
    }
}

final class LeadCreatedHandler implements EventHandlerInterface
{
    public function __invoke(LeadCreated $event): void
    {
    }
}

$command = new CreateLeadCommand('lead@example.test');
$query = new GetLeadQuery('lead-1');
$event = new LeadCreated('lead-1');

expectApplication($command instanceof CommandInterface, 'Command must implement the canonical command marker.');
expectApplication($query instanceof QueryInterface, 'Query must implement the canonical query marker.');
expectApplication($event instanceof EventInterface, 'Event must implement the canonical event marker.');
expectApplication(is_callable(new CreateLeadHandler()), 'Command handler must be invokable.');
expectApplication(is_callable(new GetLeadHandler()), 'Query handler must be invokable.');
expectApplication(is_callable(new LeadCreatedHandler()), 'Event handler must be invokable.');
expectApplication(interface_exists(CommandBusInterface::class), 'Command bus contract must autoload.');
expectApplication(interface_exists(QueryBusInterface::class), 'Query bus contract must autoload.');
expectApplication(interface_exists(EventBusInterface::class), 'Event bus contract must autoload.');

echo "Kernel Application CQRS foundation contract passed without Symfony kernel.\n";
