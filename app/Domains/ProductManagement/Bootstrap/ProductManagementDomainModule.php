<?php
declare(strict_types=1);

namespace Domains\ProductManagement\Bootstrap;

use Kernel\Module\DomainModuleInterface;
use Kernel\Module\Contract\ActionOwningModuleInterface;
use Kernel\Module\Contract\PolicyProvidingModuleInterface;
use Kernel\Module\Contract\BootstrapPolicyProvidingModuleInterface;
use Kernel\Policy\ActionPolicy;
use Kernel\Policy\PolicyDecision;
use Domains\ProductManagement\Automation\Action\ProductImportApplyHandler;
use Domains\ProductManagement\Automation\Action\ProductStockCountApplyHandler;
use Domains\ProductManagement\Automation\Action\ProductStockCountSessionApplyHandler;
use Domains\ProductManagement\Automation\Action\ProductGovernedOperationHandler;
use Domains\ProductManagement\Automation\Action\ShopifyImportApplyHandler;
use Domains\ProductManagement\Automation\Action\ShopifyDraftWritebackHandler;
use Kernel\Module\Contract\EventOwningModuleInterface;
use Kernel\Rule\Contract\RuleContextProviderInterface;
use Domains\ProductManagement\Rule\ProductManagementRuleContextProvider;

/** Business event owner, not an independent scheduler or executor. */
final readonly class ProductManagementDomainModule implements DomainModuleInterface, EventOwningModuleInterface,
    ActionOwningModuleInterface, PolicyProvidingModuleInterface, BootstrapPolicyProvidingModuleInterface
{
    public function __construct(
        private ?ProductImportApplyHandler $importHandler = null,
        private ?ShopifyImportApplyHandler $shopifyHandler = null,
        private ?ProductStockCountApplyHandler $stockCountHandler = null,
        private ?ProductStockCountSessionApplyHandler $countSessionHandler = null,
        private ?ProductGovernedOperationHandler $governedHandler = null,
        private ?ShopifyDraftWritebackHandler $writebackHandler = null,
    ) {}

    public function name(): string { return 'product_management'; }
    public function actionTypes():array {
        return array_merge(array_values(array_filter([
            $this->importHandler===null?null:ProductImportApplyHandler::TYPE,
            $this->shopifyHandler===null?null:ShopifyImportApplyHandler::TYPE,
            $this->writebackHandler===null?null:ShopifyDraftWritebackHandler::TYPE,
            $this->writebackHandler===null?null:ShopifyDraftWritebackHandler::PUBLISH,
            $this->stockCountHandler===null?null:ProductStockCountApplyHandler::TYPE,
            $this->countSessionHandler===null?null:ProductStockCountSessionApplyHandler::TYPE,
        ])), $this->governedHandler===null?[]:ProductGovernedOperationHandler::types());
    }

    public function actionHandlers():array {
        return array_values(array_filter([$this->importHandler,$this->shopifyHandler,$this->stockCountHandler,$this->countSessionHandler,$this->governedHandler,$this->writebackHandler]));
    }

    public function policies(string $organizationId):array {
        $policies=[];
        if($this->importHandler!==null) {
            $policyId=substr(hash('sha256',$organizationId.':product-import-approved-v1'),0,32);
            $policies[]=new ActionPolicy($policyId,$organizationId,ProductImportApplyHandler::TYPE,[],
                PolicyDecision::ApprovalRequired,10,'CSV requires independently approved canonical Action.');
        }
        if($this->shopifyHandler!==null) {
            $policyId=substr(hash('sha256',$organizationId.':shopify-import-approved-v1'),0,32);
            $policies[]=new ActionPolicy($policyId,$organizationId,ShopifyImportApplyHandler::TYPE,[],
                PolicyDecision::ApprovalRequired,10,'Shopify import requires independently approved canonical Action.');
        }
        if($this->writebackHandler!==null) {
            $policyId=substr(hash('sha256',$organizationId.':shopify-draft-writeback-approved-v1'),0,32);
            $policies[]=new ActionPolicy($policyId,$organizationId,ShopifyDraftWritebackHandler::TYPE,[],
                PolicyDecision::ApprovalRequired,10,'Shopify draft revision requires independent approved canonical Action.');
            $policies[]=new ActionPolicy(substr(hash('sha256',$organizationId.':shopify-publication-approved-v1'),0,32),$organizationId,ShopifyDraftWritebackHandler::PUBLISH,[],
                PolicyDecision::ApprovalRequired,10,'Shopify publication requires independent approved canonical Action.');
        }
        if($this->stockCountHandler!==null) {
            $policyId=substr(hash('sha256',$organizationId.':product-stock-count-approved-v1'),0,32);
            $policies[]=new ActionPolicy($policyId,$organizationId,ProductStockCountApplyHandler::TYPE,[],
                PolicyDecision::ApprovalRequired,10,'Physical stock count requires independent approved canonical Action.');
        }
        if($this->countSessionHandler!==null) {
            $policyId=substr(hash('sha256',$organizationId.':product-stock-session-approved-v1'),0,32);
            $policies[]=new ActionPolicy($policyId,$organizationId,ProductStockCountSessionApplyHandler::TYPE,[],
                PolicyDecision::ApprovalRequired,10,'Atomic physical count session requires independent approval.');
        }
        if($this->governedHandler!==null)foreach(ProductGovernedOperationHandler::types() as $type){
            $policies[]=new ActionPolicy(substr(hash('sha256',$organizationId.':'.$type.':v1'),0,32),
                $organizationId,$type,[],PolicyDecision::ApprovalRequired,10,'Independent approved Product business operation.');
        }
        return $policies;
    }

    public function bootstrapPolicies():array {return $this->policies('default');}


    public function ruleContextProvider(): RuleContextProviderInterface
    {
        return new ProductManagementRuleContextProvider();
    }

    public function eventTypes(): array
    {
        return [
            'product_management.product.created',
            'product_management.product.assembled',
            'product_management.translation.upserted',
            'product_management.media.attached',
            'product_management.media.detached',
            'product_management.variant.created',
            'product_management.variant.updated',
            'product_management.attribute.defined',
            'product_management.attribute.lifecycle_changed',
            'product_management.attribute.category_bound',
            'product_management.attribute.product_set',
            'product_management.attribute.bulk_set',
            'product_management.variant.matrix_created',
            'product_management.variant.archived',
            'product_management.category.created',
            'product_management.category.assigned',
            'product_management.location.created',
            'product_management.stock.movement_posted',
            'product_management.stock.transferred',
            'product_management.stock.reserved',
            'product_management.stock.released',
            'product_management.stock.order_reserved',
            'product_management.stock.order_commit',
            'product_management.stock.order_release',
            'product_management.stock.order_return',
            'product_management.stock.count_applied',
            'product_management.stock.count_session_applied',
            'product_management.price.updated',
            'product_management.price_list.created',
            'product_management.price_list.updated',
            'product_management.price_list.archived',
            'product_management.price_list.entry_updated',
            'product_management.price_list.entry_removed',
            'product_management.price_list.tier_changed',
        ];
    }
}
