<?php
declare(strict_types=1);

namespace App\Command;

use App\Web\Experience\External\ExternalReferenceAuditService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'cos:experience:external:reference',
    description: 'Audit the four EX-006 Portal/Public reference surfaces.',
)]
final class ExternalReferenceAuditCommand extends Command
{
    public function __construct(private readonly ExternalReferenceAuditService $audit)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('strict', null, InputOption::VALUE_NONE, 'Fail unless all four external reference surfaces preserve canonical structure.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $report = $this->audit->audit();

        $rows = [];
        foreach ($report->pages as $page) {
            $issues = array_merge(
                array_map(static fn (string $x): string => 'missing '.$x, $page['missing']),
                array_map(static fn (string $x): string => 'forbidden '.$x, $page['forbidden']),
            );
            $rows[] = [$page['id'], $page['surface'], $page['archetype'], $page['passed'] ? 'PASS' : 'FAIL', implode('; ', $issues)];
        }

        $io->title('COS EX-006 External Reference Audit');
        $io->table(['Reference', 'Surface', 'Archetype', 'Structure', 'Issues'], $rows);
        $io->writeln(sprintf('<info>%d</info> / <info>%d</info> external references canonical.', $report->passed(), count($report->pages)));

        return $input->getOption('strict') && !$report->isGreen()
            ? Command::FAILURE
            : Command::SUCCESS;
    }
}
