<?php
declare(strict_types=1);

namespace App\Command;

use DomainException;
use Domains\ProductManagement\Infrastructure\Shopify\ShopifyCatalogPullService;
use Domains\ProductManagement\Infrastructure\Shopify\ShopifyClientCredentials;
use Domains\ProductManagement\Infrastructure\Shopify\ShopifyObservationStore;
use Domains\ProductManagement\Infrastructure\Shopify\ShopifyReadClient;
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
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('action',InputArgument::REQUIRED,'provision|status|pull')
            ->addOption('organization',null,InputOption::VALUE_REQUIRED,'Explicit tenant organization')
            ->addOption('shop',null,InputOption::VALUE_REQUIRED,'Existing <shop>.myshopify.com name')
            ->addOption('secret-ref',null,InputOption::VALUE_REQUIRED,'Vault ref env://COS_SHOPIFY_* or pilot legacy','shopify_env_primary')
            ->addOption('connection',null,InputOption::VALUE_REQUIRED,'Existing 32-character connection ID')
            ->addOption('pages',null,InputOption::VALUE_REQUIRED,'Maximum 1..20 product pages','1')
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
            if (!in_array($action,['provision','status','pull'],true)) {
                return $this->result($output,['status'=>'INVALID_ACTION'],Command::INVALID);
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
