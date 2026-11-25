<?php
declare(strict_types=1);

namespace App\Command;

use Domains\ProductManagement\Application\Service\CsvProductImportService;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Module\ModuleControlService;
use Kernel\Module\ModuleReadinessDiagnostic;
use Kernel\Observability\CorrelationId;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use RuntimeException;
use Throwable;

/**
 * One-tenant supervised pilot setup; not an import executor, Policy authority,
 * user impersonation or Shopify writeback. No method invokes applyApproved.
 */
#[AsCommand(name:'cos:product:series01',description:'Supervised tenant activation and immutable SERIES01 preview (never apply).')]
final class ProductManagementSeries01CutoverCommand extends Command
{
    private const TENANT='default';
    private const MODULE='product_management';
    private const SERVICE_ACTOR='cos-supervised-deploy';
    private const ENABLER='ENABLE_PRODUCT_MANAGEMENT_DEFAULT';
    private const PREVIEW_CONFIRM='STAGE_SERIES01_PREVIEW_ONLY';

    public function __construct(
        private readonly ModuleControlService $control,
        private readonly ActiveModuleResolver $modules,
        private readonly ModuleReadinessDiagnostic $readiness,
        private readonly CsvProductImportService $imports,
    ){
        parent::__construct();
    }

    protected function configure():void
    {
        $this->addArgument('action',InputArgument::REQUIRED,'status|enable|preview')
            ->addOption('organization',null,InputOption::VALUE_REQUIRED,'Exact tenant ID (default only)')
            ->addOption('confirm',null,InputOption::VALUE_REQUIRED,'Explicit operation confirmation');
    }

    protected function execute(InputInterface $input,OutputInterface $output):int
    {
        if(getenv('COS_PRODUCT_SERIES01_OPERATOR_ENABLED')!=='1') {
            return $this->respond($output,['status'=>'OPERATOR_DISABLED'],Command::FAILURE);
        }
        $org=(string)$input->getOption('organization');
        if($org!==self::TENANT) {
            return $this->respond($output,['status'=>'TENANT_MISMATCH'],Command::INVALID);
        }
        $action=(string)$input->getArgument('action');
        try {
            return match($action) {
                'status'=>$this->status($output),
                'enable'=>$this->enable($input,$output),
                'preview'=>$this->preview($input,$output),
                default=>$this->respond($output,['status'=>'UNKNOWN_ACTION'],Command::INVALID),
            };
        } catch(Throwable $e) {
            error_log('series01_pilot_cutover_rejected '.get_class($e));
            return $this->respond($output,[
                'status'=>'REJECTED','action'=>$action,'error_type'=>get_class($e)
            ],Command::FAILURE);
        }
    }

    private function status(OutputInterface $output):int
    {
        $diag=$this->moduleDiagnostic();
        $state=$this->modules->describeModule(self::TENANT,self::MODULE);
        return $this->respond($output,[
            'status'=>(string)$diag['status'],
            'organization_id'=>self::TENANT,
            'module_id'=>self::MODULE,
            'active'=>(bool)($state['active']??false),
            'current'=>(bool)($state['current']??false),
            'configured_enabled'=>(bool)($state['configured_enabled']??false),
            'missing_migrations'=>$diag['missing_migrations']??[],
        ],Command::SUCCESS);
    }

    private function enable(InputInterface $input,OutputInterface $output):int
    {
        $this->confirm($input,self::ENABLER);
        $diag=$this->moduleDiagnostic();
        if(($diag['missing_migrations']??[])!==[]
            ||in_array(($diag['status']??null),['SCHEMA_STATUS_UNAVAILABLE','DEPENDENCY_NOT_READY'],true)) {
            throw new RuntimeException('Module schema or dependency preflight not ready.');
        }
        $before=$this->modules->describeModule(self::TENANT,self::MODULE);
        if(($diag['status']??null)==='READY' && ($before['active']??false)===true) {
            return $this->respond($output,[
                'status'=>'ALREADY_ACTIVE','organization_id'=>self::TENANT,
            ],Command::SUCCESS);
        }
        $correlation=CorrelationId::generate()->value();
        $reason='User-requested SERIES01 activation, supervised AWS dev cutover.';
        $wasActive=($before['active']??false)===true;
        try {
            if(!($before['installed']??false)) {
                $this->control->install(self::TENANT,self::MODULE,self::SERVICE_ACTOR,$correlation,$reason);
            } elseif(!($before['current']??false)) {
                $this->control->upgrade(self::TENANT,self::MODULE,self::SERVICE_ACTOR,$correlation,$reason);
                $this->control->enable(self::TENANT,self::MODULE,self::SERVICE_ACTOR,
                    CorrelationId::generate()->value(),$reason);
            } else {
                $this->control->enable(self::TENANT,self::MODULE,self::SERVICE_ACTOR,$correlation,$reason);
            }
            $after=$this->moduleDiagnostic();
            if(($after['status']??null)!=='READY'
                ||!$this->modules->isEnabled(self::TENANT,self::MODULE)) {
                throw new RuntimeException('Module activation did not pass independent readiness.');
            }
            return $this->respond($output,[
                'status'=>'MODULE_READY','organization_id'=>self::TENANT,
                'module_id'=>self::MODULE,'audit_correlation_id'=>$correlation,
            ],Command::SUCCESS);
        } catch(Throwable $e) {
            if(!$wasActive && $this->modules->isEnabled(self::TENANT,self::MODULE)) {
                try {
                    $this->control->disable(self::TENANT,self::MODULE,self::SERVICE_ACTOR,
                        CorrelationId::generate()->value(),'SERIES01 enable readiness failed: fail-closed.');
                } catch(Throwable) {
                    error_log('series01_module_activation_rollback_failed');
                }
            }
            throw $e;
        }
    }

    private function preview(InputInterface $input,OutputInterface $output):int
    {
        $this->confirm($input,self::PREVIEW_CONFIRM);
        if(!$this->modules->isEnabled(self::TENANT,self::MODULE)
            ||($this->moduleDiagnostic()['status']??null)!=='READY') {
            throw new RuntimeException('Product Management is not READY for tenant default.');
        }
        // Runtime container intentionally excludes pilot fixtures. Read the
        // exact commit-pinned CSV supplied from trusted Actions checkout via
        // SSH stdin -> docker exec -i. Never rely on mutable container files.
        $csv=stream_get_contents(STDIN,12001);
        if(!is_string($csv)||strlen($csv)>12000
            ||!hash_equals('f8122e58f81f61d112be1bd0db0e5ae8f20fda5d6dcce57eab4fa69a0e2a058c',
                hash('sha256',$csv))
            ||substr_count(rtrim($csv,"\n"),"\n")!==9) {
            throw new RuntimeException('SERIES01 pinned CSV digest or nine-row count mismatch.');
        }
        $digest=hash('sha256',$csv);
        $report=$this->imports->preview(self::TENANT,$csv,self::SERVICE_ACTOR,
            'series01-preview-'.$digest);
        $counts=$report['counts']??[];
        if(($counts['ready']??null)!==9||($report['state']??null)!=='staged') {
            return $this->respond($output,[
                'status'=>'IMPORT_NOT_READY',
                'batch_id'=>$report['batch_id']??null,
                'counts'=>$counts,
                'state'=>$report['state']??null,
            ],Command::FAILURE);
        }
        return $this->respond($output,[
            'status'=>'PREVIEW_STAGED_AWAITING_INDEPENDENT_APPROVAL',
            'organization_id'=>self::TENANT,
            'batch_id'=>$report['batch_id'],
            'batch_digest'=>$report['digest'],
            'rows_digest'=>$report['rows_digest'],
            'counts'=>$counts,
            'canonical_products_written'=>0,
            'next'=>'Human tenant manager must propose Goal/Plan; independent manager approves Plan and Action via canonical Workflow.',
        ],Command::SUCCESS);
    }

    /** @return array<string,mixed> */
    private function moduleDiagnostic():array
    {
        $report=$this->readiness->diagnose(self::TENANT);
        foreach(($report['modules']??[]) as $module) {
            if(($module['id']??null)===self::MODULE)return $module;
        }
        throw new RuntimeException('Product Management is not registered in ModuleCatalog.');
    }

    private function confirm(InputInterface $input,string $expected):void
    {
        if((string)$input->getOption('confirm')!==$expected) {
            throw new RuntimeException('Explicit supervised cutover confirmation missing.');
        }
    }

    /** @param array<string,mixed> $data */
    private function respond(OutputInterface $output,array $data,int $code):int
    {
        $output->writeln(json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
        return $code;
    }
}
