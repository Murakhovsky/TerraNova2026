<?php
declare(strict_types=1);

namespace Domains\Property\Bootstrap;

use Domains\Property\Automation\Action\PropertyBulkCreateHandler;
use Domains\Property\Automation\Event\PropertyEventType;
use Domains\Property\Rule\PropertyRuleContextProvider;
use Kernel\Module\Contract\ActionOwningModuleInterface;
use Kernel\Module\Contract\BootstrapPolicyProvidingModuleInterface;
use Kernel\Module\Contract\EventOwningModuleInterface;
use Kernel\Module\Contract\PolicyProvidingModuleInterface;
use Kernel\Module\Contract\RuleProvidingModuleInterface;
use Kernel\Module\DomainModuleInterface;
use Kernel\Policy\ActionPolicy;
use Kernel\Policy\PolicyDecision;
use Kernel\Rule\Contract\RuleContextProviderInterface;
use Kernel\Rule\Rule;

/** Property remains the sole writer. Bulk Action uses the canonical worker. */
final readonly class PropertyDomainModule implements
    DomainModuleInterface, RuleProvidingModuleInterface,
    EventOwningModuleInterface, ActionOwningModuleInterface,
    PolicyProvidingModuleInterface, BootstrapPolicyProvidingModuleInterface
{
    private RuleContextProviderInterface $ruleContextProvider;

    public function __construct(
        ?RuleContextProviderInterface $ruleContextProvider = null,
        private ?PropertyBulkCreateHandler $bulkHandler = null,
    ) {
        $this->ruleContextProvider = $ruleContextProvider ?? new PropertyRuleContextProvider();
    }

    public function name():string { return 'property'; }
    public function eventTypes():array { return PropertyEventType::values(); }
    public function ruleContextProvider():RuleContextProviderInterface { return $this->ruleContextProvider; }
    /** @return list<Rule> */
    public function rules(string $organizationId):array { return []; }

    public function actionTypes():array {
        return $this->bulkHandler===null ? [] : [PropertyBulkCreateHandler::TYPE];
    }

    public function actionHandlers():array {
        return $this->bulkHandler===null ? [] : [$this->bulkHandler];
    }

    /** @return list<ActionPolicy> */
    public function policies(string $organizationId):array {
        if ($this->bulkHandler===null) return [];
        $id=$organizationId==='default'?'property-bulk-approval-v1'
            :substr(hash('sha256',$organizationId.':property-bulk-approval-v1'),0,32);
        return [new ActionPolicy($id,$organizationId,PropertyBulkCreateHandler::TYPE,[],
            PolicyDecision::ApprovalRequired,10,'Approved Property batch creates native Assets, never publishes')];
    }

    /** @return list<ActionPolicy> */
    public function bootstrapPolicies():array { return $this->policies('default'); }
}
