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
use Kernel\Module\Contract\EventOwningModuleInterface;
use Kernel\Rule\Contract\RuleContextProviderInterface;
use Domains\ProductManagement\Rule\ProductManagementRuleContextProvider;

/** Business event owner, not an independent scheduler or executor. */
final readonly class ProductManagementDomainModule implements DomainModuleInterface, EventOwningModuleInterface,
    ActionOwningModuleInterface, PolicyProvidingModuleInterface, BootstrapPolicyProvidingModuleInterface
{
    public function __construct(private ?ProductImportApplyHandler $importHandler = null) {}

    public function name(): string { return 'product_management'; }
    public function actionTypes():array {
        return $this->importHandler===null?[]:[ProductImportApplyHandler::TYPE];
    }

    public function actionHandlers():array {
        return $this->importHandler===null?[]:[$this->importHandler];
    }

    public function policies(string $organizationId):array {
        if($this->importHandler===null)return [];
        $policyId=substr(hash('sha256',$organizationId.':product-import-approved-v1'),0,32);
        return [new ActionPolicy($policyId,$organizationId,ProductImportApplyHandler::TYPE,[],
            PolicyDecision::ApprovalRequired,10,
            'Product CSV apply is a high-risk canonical approved Action, not a direct import.')];
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
            'product_management.translation.upserted',
            'product_management.variant.created',
            'product_management.variant.updated',
            'product_management.variant.archived',
            'product_management.category.created',
            'product_management.category.assigned',
            'product_management.location.created',
            'product_management.stock.movement_posted',
            'product_management.stock.transferred',
            'product_management.price.updated',
        ];
    }
}
