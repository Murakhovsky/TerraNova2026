<?php
declare(strict_types=1);
namespace Domains\Marketing\Bootstrap;

use Domains\Marketing\Automation\Action\MarketingAnalystRunHandler;
use Kernel\Module\DomainModuleInterface;
use Kernel\Module\Contract\ActionOwningModuleInterface;
use Kernel\Module\Contract\PolicyProvidingModuleInterface;
use Kernel\Module\Contract\BootstrapPolicyProvidingModuleInterface;
use Kernel\Policy\ActionPolicy;
use Kernel\Policy\PolicyDecision;

final readonly class MarketingDomainModule implements DomainModuleInterface,ActionOwningModuleInterface,
    PolicyProvidingModuleInterface,BootstrapPolicyProvidingModuleInterface
{
    public function __construct(private MarketingAnalystRunHandler $analyst){}
    public function name():string{return 'marketing';}
    public function actionTypes():array{return [MarketingAnalystRunHandler::TYPE];}
    public function actionHandlers():array{return [$this->analyst];}
    public function policies(string $organizationId):array{
        return [new ActionPolicy(substr(hash('sha256',$organizationId.':marketing-analyst-approved-v1'),0,32),
            $organizationId,MarketingAnalystRunHandler::TYPE,[],PolicyDecision::ApprovalRequired,10,
            'Hermes advisory execution requires independently approved canonical Workflow Action.')];
    }
    public function bootstrapPolicies():array{return $this->policies('default');}
}
