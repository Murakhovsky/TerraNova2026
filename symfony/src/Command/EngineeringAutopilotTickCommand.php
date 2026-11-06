<?php
declare(strict_types=1);

namespace App\Command;

use App\Engineering\Application\Service\EngineeringDevelopmentAutopilot;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(name: 'cos:engineering:autopilot:tick', description: 'Preview or admit one governed GitHub Issue into Engineering Runtime.')]
final class EngineeringAutopilotTickCommand extends Command
{
    public function __construct(
        private readonly EngineeringDevelopmentAutopilot $autopilot,
        private readonly string $organizationId,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('execute', null, InputOption::VALUE_NONE,
                'Queue exactly one eligible issue. Default is read-only preview.')
            ->addOption('scan-limit', null, InputOption::VALUE_REQUIRED,
                'Maximum candidate issues to scan, 1..100.', '50');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $limit = filter_var($input->getOption('scan-limit'), FILTER_VALIDATE_INT);
            if ($limit === false || $limit < 1 || $limit > 100) {
                $output->writeln('<error>scan-limit must be 1..100.</error>');
                return Command::INVALID;
            }
            $result = $this->autopilot->tick(
                $this->organizationId,
                (bool) $input->getOption('execute'),
                $limit,
            );
            $output->writeln(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT
                | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        } catch (Throwable $error) {
            $output->writeln('<error>Autopilot stopped: '.htmlspecialchars(
                $error->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8',
            ).'</error>');
            return Command::FAILURE;
        }
    }
}
