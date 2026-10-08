<?php
declare(strict_types=1);

namespace App\Command;

use Domains\CapitalMarkets\Application\Contract\PortfolioNavFinancialEvidenceRepositoryInterface;
use Domains\CapitalMarkets\Application\Service\PortfolioNavFinancialEvidencePolicy;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Operator-only local source import. Records raw evidence for human/source
 * reconciliation; cannot set an approved financial balance or create NAV.
 */
#[AsCommand(
    name: 'cos:capital-markets:nav:evidence:import',
    description: 'Append one tenant-scoped financial source document as pending NAV evidence.',
)]
final class CapitalMarketsNavEvidenceImportCommand extends Command
{
    public function __construct(private readonly PortfolioNavFinancialEvidenceRepositoryInterface $evidence)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('organization', null, InputOption::VALUE_REQUIRED, 'Organization ID')
            ->addOption('portfolio', null, InputOption::VALUE_REQUIRED, 'Portfolio ID', 'paper-master')
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Path to a local JSON source-evidence document')
            ->addOption('source-file', null, InputOption::VALUE_REQUIRED, 'Actual independent source document, verified against its SHA-256');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $organization = trim((string)$input->getOption('organization'));
        $portfolio = trim((string)$input->getOption('portfolio'));
        $file = trim((string)$input->getOption('file'));
        $sourceFile = trim((string)$input->getOption('source-file'));
        if ($organization === '' || $portfolio === '' || $file === '' || $sourceFile === '') {
            $output->writeln(json_encode(['status'=>'REJECTED','reason'=>'ORGANIZATION_PORTFOLIO_AND_BOTH_FILES_REQUIRED'], JSON_THROW_ON_ERROR));
            return Command::INVALID;
        }
        if (!is_file($file) || !is_readable($file) || filesize($file) === false
            || filesize($file) > 1048576 || filesize($file) === 0) {
            $output->writeln(json_encode(['status'=>'REJECTED','reason'=>'LOCAL_SOURCE_FILE_INVALID'], JSON_THROW_ON_ERROR));
            return Command::FAILURE;
        }
        try {
            $raw = file_get_contents($file);
            if (!is_string($raw)) throw new \RuntimeException('Unable to read source document.');
            $document = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($document) || array_is_list($document)) {
                throw new \InvalidArgumentException('One object-shaped source record is required.');
            }
            // Validate before writing; even an attempted authority escalation is normalized to PENDING.
            $normalized = PortfolioNavFinancialEvidencePolicy::normalize($document);
            // Never trust a hash supplied inside JSON without checking the original bytes.
            if (!is_file($sourceFile) || !is_readable($sourceFile) || is_link($sourceFile)) {
                throw new \InvalidArgumentException('Independent source document unavailable.');
            }
            $sourceSize = filesize($sourceFile);
            if ($sourceSize === false || $sourceSize <= 0 || $sourceSize > 20971520) {
                throw new \InvalidArgumentException('Independent source document exceeds allowed bounds.');
            }
            $sourceDigest = hash_file('sha256', $sourceFile);
            if (!is_string($sourceDigest) || !hash_equals($normalized['source_document_sha256'], $sourceDigest)) {
                throw new \InvalidArgumentException('Independent document hash does not match.');
            }
            $this->evidence->append($organization, $portfolio, $normalized);
            $output->writeln(json_encode([
                'status'=>'RECORDED_PENDING_RECONCILIATION',
                'kind'=>$normalized['kind'],
                'evidence_id'=>$normalized['evidence_id'],
                'approved'=>false,
                'nav_snapshot_written'=>false,
            ], JSON_THROW_ON_ERROR));
            return Command::SUCCESS;
        } catch (Throwable) {
            // Do not emit raw source documents, personal financial content or stack traces.
            $output->writeln(json_encode(['status'=>'REJECTED','reason'=>'SOURCE_VALIDATION_OR_STORAGE_FAILED'], JSON_THROW_ON_ERROR));
            return Command::FAILURE;
        }
    }
}
