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
        // CI identity must arrive from the runner, not the imported artifact.
        // Offline/operator artifacts are intentionally not trusted as CI PASS.
        $trusted = [
            'source_commit_sha' => getenv('COS_GOLDEN_CI_SOURCE_SHA') ?: '',
            'tested_checkout_sha' => getenv('GITHUB_SHA') ?: '',
            'workflow_run_id' => getenv('GITHUB_RUN_ID') ?: '',
            'workflow_run_attempt' => getenv('GITHUB_RUN_ATTEMPT') ?: '',
        ];
        if (getenv('GITHUB_ACTIONS') !== 'true'
            || getenv('GITHUB_REPOSITORY') !== 'Murakhovsky/TerraNova2026'
            || !preg_match('/^[a-f0-9]{40}$/i', $trusted['source_commit_sha'])
            || !preg_match('/^[a-f0-9]{40}$/i', $trusted['tested_checkout_sha'])
            || !preg_match('/^[1-9][0-9]*$/', $trusted['workflow_run_id'])
            || !preg_match('/^[1-9][0-9]*$/', $trusted['workflow_run_attempt'])) {
            throw new \RuntimeException('Golden QA PASS import requires independently provided GitHub Actions run identity.');
        }
        $report = $this->evidence->ingest($source, $trusted);
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
