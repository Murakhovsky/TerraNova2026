<?php
declare(strict_types=1);

namespace App\Command;

use App\Web\Experience\Governance\GoldenQaEvidenceRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'cos:experience:qa:ingest', description: 'Validate Golden Eight CI evidence and publish a Page Contract QA projection without human acceptance.')]
final class ExperienceQaIngestCommand extends Command
{
    public function __construct(private readonly GoldenQaEvidenceRegistry $evidence)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('source', null, InputOption::VALUE_REQUIRED, 'Directory with Golden Eight summary, screenshots and Axe reports.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output the reconciled projection.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $source = trim((string) $input->getOption('source'));
        if ($source === '') {
            throw new \InvalidArgumentException('Pass --source=/path/to/golden-eight CI artifact.');
        }
        $report = $this->evidence->ingest($source);
        if ($input->getOption('json')) {
            $output->writeln(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            (new SymfonyStyle($input, $output))->success(sprintf(
                '%d Golden pages reconciled from CI run %s. Human acceptance unchanged.',
                count($report['pages']),
                $report['workflow_run_id'],
            ));
        }
        return Command::SUCCESS;
    }
}
