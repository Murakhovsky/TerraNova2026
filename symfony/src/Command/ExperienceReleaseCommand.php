<?php
declare(strict_types=1);

namespace App\Command;

use App\Web\Experience\Release\ExperienceReleaseHardeningService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'cos:experience:release',
    description: 'Report EX-007 Experience V1 release-hardening gates.',
)]
final class ExperienceReleaseCommand extends Command
{
    public function __construct(private readonly ExperienceReleaseHardeningService $hardening)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output structured JSON.')
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Fail unless every Experience V1 release gate is green.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $report = $this->hardening->report();
        if ($input->getOption('json')) {
            $output->writeln(json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return $input->getOption('strict') && !$report->isReady() ? Command::FAILURE : Command::SUCCESS;
        }

        $io = new SymfonyStyle($input, $output);
        $io->title('COS Experience V1 Release Hardening');
        $io->writeln('Status: <'.($report->isReady() ? 'info' : 'comment').'>'.$report->status.'</'.($report->isReady() ? 'info' : 'comment').'>');

        $rows = [];
        foreach ($report->gates as $name => $gate) {
            $actual = array_key_exists('actual', $gate) ? (string) $gate['actual'] : (string) ($gate['status'] ?? '—');
            $required = array_key_exists('required', $gate) ? (string) $gate['required'] : 'PASS';
            $rows[] = [$name, $actual, $required, ($gate['pass'] ?? false) ? 'PASS' : 'BLOCKED'];
        }
        $io->table(['Gate', 'Actual', 'Required', 'State'], $rows);

        if ($report->blockers !== []) {
            $io->warning('Release blockers: '.implode(', ', $report->blockers));
        }

        return $input->getOption('strict') && !$report->isReady()
            ? Command::FAILURE
            : Command::SUCCESS;
    }
}
