<?php
declare(strict_types=1);

namespace App\Command;

use App\Persistence\Workflow\DoctrineWorkflowShadowParityReader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Local operator-only read-only parity report; no web controller or writer. */
#[AsCommand(name:'cos:workflow:shadow-audit',
    description:'Compare authoritative legacy Workflow with canonical technical execution, read-only.')]
final class WorkflowShadowAuditCommand extends Command
{
    public function __construct(private readonly DoctrineWorkflowShadowParityReader $reader)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('source',InputArgument::REQUIRED,'federation, engineering-agent, engineering-workflow or engineering-fleet');
        $this->addArgument('organization',InputArgument::REQUIRED,'Verified tenant organization ID');
        $this->addArgument('run',InputArgument::OPTIONAL,'Legacy run/AgentRun ID (omit for engineering-fleet)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $source=(string)$input->getArgument('source');
        $org=(string)$input->getArgument('organization');
        $run=(string)$input->getArgument('run');
        if ($source==='engineering-fleet') {
            $report=$this->reader->engineeringFleet($org);
            $output->writeln(json_encode($report,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
            // Fleet aggregation cannot approve a business Workflow cutover.
            // PARTIAL compares technical execution only; it is never release readiness.
            return Command::FAILURE;
        }
        if ($run==='' && $source!=='engineering-fleet') {
            throw new \InvalidArgumentException('Source-scoped audit requires an explicit legacy Run/AgentRun ID.');
        }
        if ($source==='engineering-workflow') {
            $report=$this->reader->engineeringWorkflow($org,$run);
            $output->writeln(json_encode($report,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
            // Technical parity never overrides Human/QA/Release ownership.
            return Command::FAILURE;
        }
        $report=match($source) {
            'federation'=>$this->reader->federation($org,$run),
            'engineering-agent'=>$this->reader->engineeringAgent($org,$run),
            default=>throw new \InvalidArgumentException('Only federation, engineering-agent, engineering-workflow and engineering-fleet are supported.'),
        };
        $output->writeln(json_encode([
            'source'=>$report->source,'run_id'=>$report->sourceRunId,
            'status'=>$report->status->value,'differences'=>$report->differences,
            'read_only'=>true,'cutover_authorized'=>false,
        ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
        return $report->status->value==='match'?Command::SUCCESS:Command::FAILURE;
    }
}
