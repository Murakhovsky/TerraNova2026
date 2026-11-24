<?php
declare(strict_types=1);

namespace App\Command;

use DomainException;
use Domains\ProductManagement\Infrastructure\Persistence\MySqlStockReconciliationAudit;
use Kernel\Module\ActiveModuleResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Gated read-only server CLI. Incomplete or divergent scans never report green.
 * This command does not claim action approval or invoke a Stock writer.
 */
#[AsCommand(name:'cos:product:stock:audit',description:'Supervised read-only tenant Stock ledger and reservation reconciliation.')]
final class ProductManagementStockAuditCommand extends Command
{
    public function __construct(
        private readonly MySqlStockReconciliationAudit $audit,
        private readonly ActiveModuleResolver $modules,
    ) { parent::__construct(); }

    protected function configure():void
    {
        $this->addOption('organization',null,InputOption::VALUE_REQUIRED,'Explicit tenant organization')
            ->addOption('limit',null,InputOption::VALUE_REQUIRED,'Maximum stock pairs 1..500','100');
    }

    protected function execute(InputInterface $input,OutputInterface $output):int
    {
        if (getenv('COS_PRODUCT_STOCK_AUDIT_ENABLED')!=='1') {
            return $this->result($output,['status'=>'DISABLED','automatic_repair_authorized'=>false],Command::FAILURE);
        }
        $org=trim((string)$input->getOption('organization'));
        $limit=filter_var($input->getOption('limit'),FILTER_VALIDATE_INT);
        if ($org==='' || strlen($org)>40
            || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]*$/D',$org)!==1
            || $limit===false || $limit<1 || $limit>500) {
            return $this->result($output,['status'=>'INVALID_ARGUMENTS'],Command::INVALID);
        }
        try {
            if (!$this->modules->isEnabled($org,'product_management')) {
                throw new DomainException('Product Management module is not active for tenant.');
            }
            $result=$this->audit->audit($org,$limit);
            return $this->result($output,$result,$result['status']==='OK'?Command::SUCCESS:Command::FAILURE);
        } catch (Throwable $error) {
            // No PDO details, SQL, or tenant internals in the terminal output.
            return $this->result($output,['status'=>'AUDIT_FAILED','reason'=>'tenant_not_ready_or_audit_failed'],
                Command::FAILURE);
        }
    }

    /** @param array<string,mixed> $body */
    private function result(OutputInterface $output,array $body,int $status):int
    {
        $output->writeln(json_encode($body,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
        return $status;
    }
}
