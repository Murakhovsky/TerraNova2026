<?php
declare(strict_types=1);

namespace App\Command;

use Domains\ProductManagement\Application\Service\CsvProductImportService;
use Domains\ProductManagement\Application\Service\ProductManagementService;
use Doctrine\DBAL\Connection;
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
    private const BATCH='ca2613c17efed1aaf6aa9c20178a70b1';
    private const CSV_DIGEST='f8122e58f81f61d112be1bd0db0e5ae8f20fda5d6dcce57eab4fa69a0e2a058c';
    private const ROWS_DIGEST='a48b950feb912f4b4065cff8dcf85943c63a525453098f186e199a7c400a0d00';

    public function __construct(
        private readonly ModuleControlService $control,
        private readonly ActiveModuleResolver $modules,
        private readonly ModuleReadinessDiagnostic $readiness,
        private readonly CsvProductImportService $imports,
        private readonly ProductManagementService $products,
        private readonly Connection $db,
    ){
        parent::__construct();
    }

    protected function configure():void
    {
        $this->addArgument('action',InputArgument::REQUIRED,'status|enable|preview|audit')
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
                'audit'=>$this->audit($output),
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

    /**
     * Read-only snapshot of the exact staged pilot and native Product records.
     * No synthetic human actor; never creates Goal, Approval, Action or Product.
     * A native readback is not a substitute for an attested Workflow receipt.
     */
    private function audit(OutputInterface $output):int
    {
        if(!$this->modules->isEnabled(self::TENANT,self::MODULE)
            ||($this->moduleDiagnostic()['status']??null)!=='READY') {
            throw new RuntimeException('Product Management not active or ready for audit.');
        }
        $report=$this->imports->report(self::TENANT,self::BATCH);
        if(($report['batch_id']??null)!==self::BATCH
            ||!hash_equals(self::CSV_DIGEST,(string)($report['digest']??''))
            ||!hash_equals(self::ROWS_DIGEST,(string)($report['rows_digest']??''))) {
            throw new RuntimeException('Native SERIES01 batch digest differs from pinned source evidence.');
        }
        $rows=$report['rows']??[];
        if(!is_array($rows)||count($rows)!==9) {
            throw new RuntimeException('Native SERIES01 batch does not contain exactly nine rows.');
        }
        $seenSku=[];$seenProduct=[];$verified=[];
        foreach($rows as $index=>$row) {
            $sku=$row['sku']??null;
            if(!is_array($row)||($row['row_no']??null)!=$index+1
                ||!is_string($sku)||!preg_match('/^SERIES01-[LFA]0[1-3]$/D',$sku)
                ||isset($seenSku[$sku])) {
                throw new RuntimeException('SERIES01 batch contains unexpected, duplicate or out-of-order SKU.');
            }
            $seenSku[$sku]=true;
            $state=$row['status']??null;
            if(!in_array($state,['ready','applied','failed'],true)) {
                throw new RuntimeException('SERIES01 native row has unexpected state.');
            }
            $id=$row['product_id']??null;
            if($state==='applied') {
                if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/D',$id)
                    ||isset($seenProduct[$id])) {
                    throw new RuntimeException('SERIES01 applied row missing unique Product ID.');
                }
                $seenProduct[$id]=true;
                $native=$this->products->getProduct(self::TENANT,$id);
                if(!is_array($native)||($native['product_id']??null)!==$id
                    ||($native['status']??null)==='archived') {
                    throw new RuntimeException('SERIES01 applied native Product missing or archived.');
                }
                $variantMatch=false;
                foreach($native['variants']??[] as $variant) {
                    if(($variant['sku']??null)===$sku)$variantMatch=true;
                }
                if(!$variantMatch)throw new RuntimeException('SERIES01 native Variant SKU mismatch.');
                $verified[]=['sku'=>$sku,'product_id'=>$id];
            } elseif($id!==null) {
                throw new RuntimeException('Unapplied SERIES01 row unexpectedly holds a native Product ID.');
            }
        }
        $goal=$report['goal_id']??null;
        $plan=$report['plan_id']??null;
        if(($goal===null)!==($plan===null)) {
            throw new RuntimeException('Partial SERIES01 Goal/Plan binding.');
        }
        $planState=null;
        $workflowRuns=[];
        if($plan!==null) {
            $planState=$this->db->fetchOne(
                'SELECT state FROM cos_federation_plans WHERE organization_id=? AND plan_id=? AND goal_id=?',
                [self::TENANT,$plan,$goal]
            );
            if(!is_string($planState))throw new RuntimeException('Bound Federation Plan absent.');
            $workflowRuns=$this->db->fetchAllAssociative(
                'SELECT run_id,state FROM cos_workflow_runs
                 WHERE organization_id=? AND source_plan_id=? ORDER BY created_at DESC LIMIT 5',
                [self::TENANT,$plan]
            );
        }
        $applied=count($verified);
        $pending=$plan===null?'goal_plan_proposal_by_authenticated_manager':
            ($planState==='approved'?'canonical_run_and_independent_action_approval':
                'independent_plan_approval');
        return $this->respond($output,[
            'status'=>$applied===9?'NATIVE_PRODUCTS_READBACK_9_OF_9':'AWAITING_APPROVED_IMPORT',
            'tenant'=>self::TENANT,
            'batch_id'=>self::BATCH,
            'batch_state'=>$report['state'],
            'counts'=>$report['counts'],
            'goal_id'=>$goal,'plan_id'=>$plan,'plan_state'=>$planState,
            'workflow_runs'=>$workflowRuns,
            'products_verified_count'=>$applied,
            'products'=>$verified,
            'workflow_receipt_independently_attested'=>false,
            'next'=>$applied===9?'independent_run_scoped_outcome_attestation':$pending,
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
