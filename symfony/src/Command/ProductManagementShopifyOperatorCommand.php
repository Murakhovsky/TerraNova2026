<?php
declare(strict_types=1);

namespace App\Command;

use DomainException;
use Domains\ProductManagement\Infrastructure\Shopify\ShopifyCatalogPullService;
use Domains\ProductManagement\Infrastructure\Shopify\ShopifyClientCredentials;
use Domains\ProductManagement\Infrastructure\Shopify\ShopifyObservationStore;
use Domains\ProductManagement\Infrastructure\Shopify\ShopifyReadClient;
use Domains\ProductManagement\Infrastructure\Shopify\ShopifyImportPreviewService;
use Domains\ProductManagement\Infrastructure\Shopify\ShopifyImportRecoveryAudit;
use Domains\ProductManagement\Infrastructure\Shopify\ShopifyOrderReadbackService;
use Domains\ProductManagement\Infrastructure\Shopify\ShopifyOrderReadbackNormalizer;
use Kernel\Module\ActiveModuleResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Explicit supervised server-operator adapter, never a background dispatcher.
 * Shopify is queried read-only; Product/Stock are never changed by this command.
 */
#[AsCommand(name:'cos:product:shopify',description:'Supervised tenant Shopify read-only provisioning, status and bounded pull.')]
final class ProductManagementShopifyOperatorCommand extends Command
{
    public function __construct(
        private readonly ShopifyObservationStore $observations,
        private readonly ShopifyCatalogPullService $puller,
        private readonly ShopifyClientCredentials $credentials,
        private readonly ActiveModuleResolver $modules,
        private readonly ShopifyImportPreviewService $previews,
        private readonly ShopifyImportRecoveryAudit $recoveryAudit,
        private readonly ShopifyOrderReadbackService $orderReadback,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('action',InputArgument::REQUIRED,'preflight|provision|status|pull|candidates|inspect|stage|audit|order')
            ->addOption('organization',null,InputOption::VALUE_REQUIRED,'Explicit tenant organization')
            ->addOption('shop',null,InputOption::VALUE_REQUIRED,'Existing <shop>.myshopify.com name')
            ->addOption('secret-ref',null,InputOption::VALUE_REQUIRED,'Vault ref env://COS_SHOPIFY_* or pilot legacy','shopify_env_primary')
            ->addOption('connection',null,InputOption::VALUE_REQUIRED,'Existing 32-character connection ID')
            ->addOption('pages',null,InputOption::VALUE_REQUIRED,'Maximum 1..20 product pages','1')
            ->addOption('limit',null,InputOption::VALUE_REQUIRED,'Maximum 1..50 snapshot candidates','20')
            ->addOption('product-gid',null,InputOption::VALUE_REQUIRED,'Observed Shopify Product GID')
            ->addOption('order-gid',null,InputOption::VALUE_REQUIRED,'Verified Shopify Order GID for read-only Order evidence')
            ->addOption('preview-id',null,InputOption::VALUE_REQUIRED,'Existing Shopify import preview ID for read-only recovery audit')
            ->addOption('expected-hash',null,InputOption::VALUE_REQUIRED,'Pinned snapshot SHA-256')
            ->addOption('confirm',null,InputOption::VALUE_REQUIRED,'PROVISION_READ_ONLY or PULL_READ_ONLY');
    }

    protected function execute(InputInterface $input,OutputInterface $output):int
    {
        // This is a server-shell-only operator command, not an authenticated
        // HTTP/API or AI/CRON invocation; operator explicitly arms the environment.
        if (getenv('COS_SHOPIFY_OPERATOR_ENABLED')!=='1') {
            return $this->result($output,['status'=>'DISABLED','reason'=>'operator_gate_off'],Command::FAILURE);
        }
        $org=trim((string)$input->getOption('organization'));
        $action=(string)$input->getArgument('action');
        if ($org===''||strlen($org)>40||!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]*$/D',$org)) {
            return $this->result($output,['status'=>'INVALID_TENANT'],Command::INVALID);
        }
        try {
            if (!in_array($action,['preflight','provision','status','pull','candidates','inspect','stage','audit','order'],true)) {
                return $this->result($output,['status'=>'INVALID_ACTION'],Command::INVALID);
            }
            // Supervised, read-only diagnostics are available even before tenant
            // activation. They do not install/enable the module or contact Shopify.
            if ($action==='preflight') {
                $module='product_management';
                $installed=$this->modules->isInstalled($org,$module);
                $current=$this->modules->isCurrent($org,$module);
                $configured=$this->modules->isConfiguredEnabled($org,$module);
                $enabled=$this->modules->isEnabled($org,$module);
                return $this->result($output,[
                    'status'=>$enabled?'MODULE_READY':'MODULE_NOT_READY',
                    'organization_id'=>$org,
                    'module_id'=>$module,
                    'installed'=>$installed,
                    'current'=>$current,
                    'configured_enabled'=>$configured,
                    'enabled'=>$enabled,
                ],$enabled?Command::SUCCESS:Command::FAILURE);
            }
            if (!$this->modules->isEnabled($org,'product_management')) {
                throw new DomainException('Product Management module not activated for tenant.');
            }
            if ($action==='provision') {
                if ($input->getOption('confirm')!=='PROVISION_READ_ONLY') {
                    return $this->result($output,['status'=>'CONFIRMATION_REQUIRED'],Command::INVALID);
                }
                $shop=strtolower(trim((string)$input->getOption('shop')));
                ShopifyReadClient::assertShopDomain($shop);
                // Verify server-held app credentials against the explicitly
                // selected shop before persisting any ready connection.
                $ref=trim((string)$input->getOption('secret-ref'));
                ShopifyClientCredentials::assertSecretReference($ref);
                $this->credentials->accessToken($shop,$ref,$org,'shopify-provision-preflight');
                $connection=$this->observations->provision($org,$shop,$ref);
                return $this->result($output,
                    ['status'=>'PROVISIONED_READ_ONLY','organization_id'=>$org,'connection_id'=>$connection],
                    Command::SUCCESS);
            }
            $connection=trim((string)$input->getOption('connection'));
            if (!preg_match('/^[a-f0-9]{32}$/D',$connection)) {
                return $this->result($output,['status'=>'INVALID_CONNECTION'],Command::INVALID);
            }
            if ($action==='status') {
                return $this->result($output,$this->observations->status($org,$connection),Command::SUCCESS);
            }
            if ($action==='candidates') {
                $limit=filter_var($input->getOption('limit'),FILTER_VALIDATE_INT);
                if ($limit===false||$limit<1||$limit>50) {
                    return $this->result($output,['status'=>'INVALID_CANDIDATE_LIMIT'],Command::INVALID);
                }
                $items=$this->observations->candidates($org,$connection,$limit);
                return $this->result($output,
                    ['status'=>'SNAPSHOT_CANDIDATES','connection_id'=>$connection,
                        'count'=>count($items),'candidates'=>$items],Command::SUCCESS);
            }
            if ($action==='order') {
                $gid=trim((string)$input->getOption('order-gid'));
                ShopifyOrderReadbackNormalizer::orderGid($gid);
                return $this->result($output,$this->orderReadback->read($org,$connection,$gid),Command::SUCCESS);
            }
            if ($action==='audit') {
                $id=trim((string)$input->getOption('preview-id'));
                $report=$this->recoveryAudit->inspect($org,$connection,$id);
                return $this->result($output,$report,Command::SUCCESS);
            }
            if ($action==='inspect') {
                $gid=trim((string)$input->getOption('product-gid'));
                if (!preg_match('#^gid://shopify/Product/[0-9]+$#D',$gid)) {
                    return $this->result($output,['status'=>'INVALID_PRODUCT_GID'],Command::INVALID);
                }
                $this->observations->connection($org,$connection);
                $snapshot=$this->observations->productSnapshot($org,$connection,$gid);
                if ($snapshot===null) {
                    return $this->result($output,['status'=>'NOT_OBSERVED'],Command::FAILURE);
                }
                return $this->result($output,[
                    'status'=>'SNAPSHOT_EVIDENCE','product_gid'=>$gid,
                    'source_hash'=>$snapshot['payload_hash'],
                    'complete'=>(int)$snapshot['is_complete']===1,
                    'observed_at'=>$snapshot['observed_at'],
                ],Command::SUCCESS);
            }
            if ($action==='stage') {
                if ($input->getOption('confirm')!=='STAGE_NO_WRITES') {
                    return $this->result($output,['status'=>'CONFIRMATION_REQUIRED'],Command::INVALID);
                }
                $gid=trim((string)$input->getOption('product-gid'));
                $hash=trim((string)$input->getOption('expected-hash'));
                $result=$this->previews->stage($org,$connection,$gid,'operator',$hash);
                return $this->result($output,$result,Command::SUCCESS);
            }
            if ($input->getOption('confirm')!=='PULL_READ_ONLY') {
                return $this->result($output,['status'=>'CONFIRMATION_REQUIRED'],Command::INVALID);
            }
            $pages=filter_var($input->getOption('pages'),FILTER_VALIDATE_INT);
            if ($pages===false || $pages<1||$pages>20) {
                return $this->result($output,['status'=>'INVALID_PAGE_LIMIT'],Command::INVALID);
            }
            $result=$this->puller->pull($org,$connection,$pages);
            return $this->result($output,$result,Command::SUCCESS);
        } catch (Throwable $e) {
            // No secrets, token, raw provider response or customer data logged.
            error_log('product_management.shopify_operator_failed '.get_class($e));
            return $this->result($output,['status'=>'FAILED','error_type'=>get_class($e)],
                Command::FAILURE);
        }
    }

    /** @param array<string,mixed> $payload */
    private function result(OutputInterface $output,array $payload,int $code):int
    {
        $output->writeln(json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
        return $code;
    }
}
