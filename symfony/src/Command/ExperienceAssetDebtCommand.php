<?php
declare(strict_types=1);

namespace App\Command;

use App\Web\Experience\Release\ExperienceAssetDebtScanner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'cos:experience:assets',
    description: 'Audit EX-007 dead CSS/JS and the canonical AssetMapper/Vite boundary.',
)]
final class ExperienceAssetDebtCommand extends Command
{
    public function __construct(private readonly ExperienceAssetDebtScanner $scanner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('strict', null, InputOption::VALUE_NONE, 'Fail when dead or legacy Experience assets remain.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $report = $this->scanner->scan();

        $io->title('COS Experience Asset Debt');
        $io->definitionList(
            ['Dead CSS' => count($report->deadCss)],
            ['Dead JS' => count($report->deadJs)],
            ['Legacy artifacts' => count($report->legacyArtifacts)],
            ['Vite boundary' => $report->viteBoundaryValid ? 'PASS' : 'FAIL'],
            ['Canonical roots' => $report->canonicalRootsPresent ? 'PASS' : 'FAIL'],
        );

        foreach ([
            'Dead CSS' => $report->deadCss,
            'Dead JS' => $report->deadJs,
            'Legacy artifacts' => $report->legacyArtifacts,
        ] as $label => $items) {
            if ($items !== []) {
                $io->section($label);
                $io->listing($items);
            }
        }

        if ($report->isGreen()) {
            $io->success('Experience asset graph is clean.');
        }

        return $input->getOption('strict') && !$report->isGreen()
            ? Command::FAILURE
            : Command::SUCCESS;
    }
}
