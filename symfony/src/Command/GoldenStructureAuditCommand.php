<?php

declare(strict_types=1);

namespace App\Command;

use App\Web\Experience\Golden\GoldenStructureAuditService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'cos:experience:golden:structure',
    description: 'Audit canonical structural composition of the Golden Experience 8.',
)]
final class GoldenStructureAuditCommand extends Command
{
    public function __construct(private readonly GoldenStructureAuditService $audit)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('strict', null, InputOption::VALUE_NONE, 'Fail when a Golden reference loses canonical structure.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $report = $this->audit->audit();

        $io->title('COS Golden 8 Structural Audit');
        $rows = [];

        foreach ($report->pages as $page) {
            $issues = array_merge(
                array_map(static fn (string $x): string => 'missing ' . $x, $page['missing']),
                array_map(static fn (string $x): string => 'forbidden ' . $x, $page['forbidden']),
            );

            $rows[] = [
                $page['id'],
                $page['passed'] ? 'PASS' : 'FAIL',
                $issues === [] ? 'canonical' : implode('; ', $issues),
            ];
        }

        $io->table(['Golden page', 'Structure', 'Evidence'], $rows);
        $io->writeln(sprintf('<info>%d</info> / <info>%d</info> structurally canonical.', $report->passed(), count($report->pages)));

        return $input->getOption('strict') && !$report->isGreen()
            ? Command::FAILURE
            : Command::SUCCESS;
    }
}
