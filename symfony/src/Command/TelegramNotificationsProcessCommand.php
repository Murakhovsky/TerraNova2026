<?php
declare(strict_types=1);

namespace App\Command;

use App\Infrastructure\Telegram\TelegramNotificationWorker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(
    name: 'cos:telegram:notifications:process',
    description: 'Process queued outbound Telegram notifications without the retired Phalcon runtime.',
)]
final class TelegramNotificationsProcessCommand extends Command
{
    public function __construct(private readonly TelegramNotificationWorker $worker)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('schedule', null, InputOption::VALUE_NONE, 'Queue due reminders before processing.')
            ->addOption('digest', null, InputOption::VALUE_NONE, 'Queue the daily management digest before processing.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum notification rows to claim.', '25');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $result = $this->worker->run(
                (bool) $input->getOption('schedule'),
                (bool) $input->getOption('digest'),
                max(1, min(100, (int) $input->getOption('limit'))),
            );
        } catch (Throwable $error) {
            $output->writeln('<error>' . $error->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $output->writeln(json_encode(
            $result,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));

        return Command::SUCCESS;
    }
}
