<?php
declare(strict_types=1);

namespace App\Command;

use App\Persistence\Federation\FederationExecutionFleetAudit;
use App\Persistence\Workflow\DoctrineWorkflowShadowParityReader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Read-only tenant fleet view across the two technical adoption tracks.
 * No session impersonation, action dispatch, migration or release control.
 */
#[AsCommand(name:'cos:workflow:authority-inventory',
    description:'Inspect tenant-wide Federation and Engineering execution provenance without promoting cutover.')]
final class WorkflowAuthorityInventoryCommand extends Command
{
    public function __construct(
        private readonly FederationExecutionFleetAudit $federation,
        private readonly DoctrineWorkflowShadowParityReader $engineering,
    ) { parent::__construct(); }

    protected function configure():void
    {
        $this->addArgument('organization',InputArgument::REQUIRED,'Explicit tenant organization ID');
    }

    protected function execute(InputInterface $input,OutputInterface $output):int
    {
        $org=trim((string)$input->getArgument('organization'));
        if($org==='' || strlen($org)>190) {
            throw new \DomainException('One bounded tenant ID is mandatory.');
        }
        $federation=$this->federation->inspect($org,100);
        $engineering=$this->engineering->engineeringFleet($org,100);
        $report=[
            'source'=>'cos_execution_authority_combined_v1',
            'organization_id'=>$org,
            'federation'=>$federation,
            'engineering'=>$engineering,
            'status'=>($federation['status']==='drift'
                || $engineering['status']==='drift')?'drift':
                (($federation['history_truncated']??true)
                 || ($engineering['history_truncated']??true)
                 || !($engineering['coverage_complete']??false)
                 || !($federation['inventory_coverage_complete']??false)
                 || $federation['status']==='no_target'
                 || $engineering['status']==='no_target'
                    ? 'incomplete':'technical_partial'),
            'read_only'=>true,
            'live_business_outcome_verified'=>false,
            'runtime_consumer_fleet_verified'=>false,
            'cutover_authorized'=>false,
            'release_authorized'=>false,
        ];
        $output->writeln(json_encode($report,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
        // This is an informational audit, not a release gate.
        // Even technical parity does not prove independent business approvals.
        return Command::FAILURE;
    }
}
