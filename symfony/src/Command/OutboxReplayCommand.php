<?php
declare(strict_types=1);

namespace App\Command;

use Kernel\Event\Service\OutboxReplayService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'cos:outbox:replay', description: 'Replay durable outbox events and consumer receipts.')]
final class OutboxReplayCommand extends Command
{
    public function __construct(private readonly OutboxReplayService $replay)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('organization', null, InputOption::VALUE_REQUIRED)
            ->addOption('event-id', null, InputOption::VALUE_REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $organizationId = trim((string) ($input->getOption('organization') ?? ''));
        $eventId = trim((string) ($input->getOption('event-id') ?? ''));
        $output->writeln(json_encode(
            $this->replay->replay($organizationId !== '' ? $organizationId : null, $eventId !== '' ? $eventId : null),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ));
        return Command::SUCCESS;
    }
}
