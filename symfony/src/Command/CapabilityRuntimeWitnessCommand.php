<?php
declare(strict_types=1);

namespace App\Command;

use DomainException;
use Kernel\Action\Service\ModuleActionExecutionGate;
use Kernel\Module\CanonicalCapabilityCatalog;
use Kernel\Module\DomainModuleRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Operator read-only proof from THIS booted Symfony worker/container.
 * Never executes handlers or treats a deployment as fleet-certified.
 */
#[AsCommand(
    name: 'cos:capability:runtime:witness',
    description: 'Read-only live Domain Action registration and Workflow worker-gate witness.'
)]
final class CapabilityRuntimeWitnessCommand extends Command
{
    public function __construct(
        private readonly CanonicalCapabilityCatalog $catalog,
        private readonly DomainModuleRegistry $domains,
        private readonly ModuleActionExecutionGate $gate,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('require-action-bindings', null, InputOption::VALUE_NONE,
            'Fail if any typed canonical Action handler is not registered in this container.');
        $this->addOption('instance-id', null, InputOption::VALUE_REQUIRED,
            'Operator-supplied exact runtime instance ID (not independently attested).');
        $this->addOption('instance-role', null, InputOption::VALUE_REQUIRED,
            'web|worker, operator-supplied role (not independently attested).');
        $this->addOption('deployment-sha', null, InputOption::VALUE_REQUIRED,
            'Operator-supplied exact deployment commit SHA (not independently attested).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $instanceId=(string)($input->getOption('instance-id')??'');
        $instanceRole=(string)($input->getOption('instance-role')??'');
        $deploymentSha=(string)($input->getOption('deployment-sha')??'');
        $supplied=$instanceId!=='' || $instanceRole!=='' || $deploymentSha!=='';
        if ($supplied && (
            preg_match('/^[a-zA-Z0-9._-]{1,128}$/D',$instanceId)!==1
            || !in_array($instanceRole,['web','worker'],true)
            || preg_match('/^[a-f0-9]{40}$/D',$deploymentSha)!==1
        )) {
            throw new DomainException('Instance ID, web/worker role and deployment SHA must be supplied together.');
        }
        $records = [];
        $issues = 0;
        $handlers = $this->domains->actionHandlerMap();
        foreach ($this->catalog->executables() as $contract) {
            $binding = $contract->executionBinding;
            $witness = 'NOT_EVALUATED_IN_THIS_COMMAND';
            $issue = null;
            if (str_starts_with($binding, 'action:')) {
                $type = substr($binding, 7);
                if ($type !== ''
                    && $this->domains->ownerOfAction($type) === $contract->ownerDomain
                    && isset($handlers[$type])
                    && $handlers[$type]->supports($type)) {
                    $witness = 'ACTION_HANDLER_REGISTERED';
                } else {
                    $witness = 'UNVERIFIED';
                    $issue = 'HANDLER_MISSING_OR_OWNER_MISMATCH';
                    ++$issues;
                }
            }
            try {
                $digest = $this->catalog->snapshot($contract->id)['contract_digest'];
            } catch (DomainException $e) {
                $digest = null;
                $issue = 'INVALID_TYPED_CONTRACT';
                ++$issues;
            }
            $records[] = [
                'id' => $contract->id,
                'owner_domain' => $contract->ownerDomain,
                'version' => $contract->version,
                'contract_digest' => $digest,
                'binding_family' => explode(':', $binding, 2)[0],
                'witness' => $witness,
                'issue' => $issue,
            ];
        }
        $mode = $this->gate->workflowAdmissionMode();
        $report = [
            'format' => 'cos-capability-runtime-instance-witness-v1',
            'scope' => 'CURRENT_SYMFONY_CONTAINER_ONLY',
            'instance_id' => $supplied ? $instanceId : 'UNATTESTED',
            'instance_role' => $supplied ? $instanceRole : 'UNATTESTED',
            'deployment_sha' => $supplied ? $deploymentSha : 'UNATTESTED',
            'instance_provenance_verified' => false,
            'worker_admission_mode' => $mode,
            'worker_execution_certified' => false,
            'tenant_policies_checked' => false,
            'approval_receipts_checked' => false,
            'native_business_outcomes_checked' => false,
            'typed_contracts' => count($records),
            'action_registration_issues' => $issues,
            'capabilities' => $records,
        ];
        $output->writeln(json_encode($report, JSON_THROW_ON_ERROR
            | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $input->getOption('require-action-bindings') && $issues > 0
            ? Command::FAILURE
            : Command::SUCCESS;
    }
}
