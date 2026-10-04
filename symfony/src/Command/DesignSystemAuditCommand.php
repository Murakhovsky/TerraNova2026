<?php

declare(strict_types=1);

namespace App\Command;

use App\Web\Experience\DesignSystem\DesignSystemAuditService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'cos:experience:design-system:audit',
    description: 'Audit and enforce the COS Design System V1 component/foundation contract.',
)]
final class DesignSystemAuditCommand extends Command
{
    public function __construct(private readonly DesignSystemAuditService $audit)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('strict', null, InputOption::VALUE_NONE, 'Fail when Design System V1 governance is incomplete.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $report = $this->audit->audit();

        $io->title('COS Design System V1 Audit');
        $io->definitionList(
            ['Component class files' => $report->componentClassFiles],
            ['Canonical Cos* components' => $report->canonicalComponents],
            ['Catalog contracts' => $report->catalogEntries],
            ['Stable / frozen' => $report->stable],
            ['Experimental' => $report->experimental],
            ['Deprecated' => $report->deprecated],
            ['Runtime helpers' => implode(', ', $report->runtimeHelpers)],
            ['Tokens frozen' => $report->tokensFrozen ? 'yes' : 'no'],
            ['Typography frozen' => $report->typographyFrozen ? 'yes' : 'no'],
            ['Theme parity required' => $report->themeParityDeclared ? 'yes' : 'no'],
        );

        if ($report->missingCatalogEntries !== []) {
            $io->error('Missing catalog entries: ' . implode(', ', $report->missingCatalogEntries));
        }
        if ($report->orphanCatalogEntries !== []) {
            $io->error('Orphan catalog entries: ' . implode(', ', $report->orphanCatalogEntries));
        }

        if ($report->isGreen()) {
            $io->success('Design System V1 audit is green.');
        }

        return $input->getOption('strict') && !$report->isGreen()
            ? Command::FAILURE
            : Command::SUCCESS;
    }
}
