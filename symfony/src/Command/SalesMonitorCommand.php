<?php
declare(strict_types=1);

namespace App\Command;

use DateTimeImmutable;
use Domains\Sales\Application\Service\SalesMonitoringService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'cos:sales:monitor', description: 'Run deterministic Sales attention detectors for one organization.')]
final class SalesMonitorCommand extends Command
{
    public function __construct(private readonly SalesMonitoringService $monitoring)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('organization', null, InputOption::VALUE_REQUIRED, 'Organization id.')
            ->addOption('no-activity-hours', null, InputOption::VALUE_REQUIRED, 'No-activity threshold.', '48')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum rows per detector.', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $organizationId = trim((string) ($input->getOption('organization') ?? ''));
        if ($organizationId === '') {
            $output->writeln('<error>--organization is required.</error>');
            return Command::INVALID;
        }

        $now = new DateTimeImmutable();
        $limit = max(1, min(1000, (int) $input->getOption('limit')));
        $output->writeln(json_encode([
            'organization_id' => $organizationId,
            'no_activity_detected' => $this->monitoring->detectNoActivity(
                $organizationId,
                $now,
                max(1, (int) $input->getOption('no-activity-hours')),
                $limit,
            ),
            'followups_missed' => $this->monitoring->detectMissedFollowups($organizationId, $now, $limit),
            'run_at' => $now->format(DATE_ATOM),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return Command::SUCCESS;
    }
}
