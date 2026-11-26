<?php
declare(strict_types=1);
namespace Domains\Marketing\Bootstrap;

use Domains\Marketing\Automation\Action\MarketingAnalystRunHandler;
use Domains\Marketing\Automation\Action\MarketingPaidLifecycleHandler;
use Domains\Marketing\Automation\Action\MarketingAttributionSweepHandler;
use Domains\Marketing\Automation\Action\MarketingOptimizationReviewHandler;
use Domains\Marketing\Automation\Action\MarketingOptimizationLearningHandler;
use Domains\Marketing\Automation\Action\MarketingOptimizationReplacementHandler;
use Kernel\Module\DomainModuleInterface;
use Kernel\Module\Contract\ActionOwningModuleInterface;
use Kernel\Module\Contract\PolicyProvidingModuleInterface;
use Kernel\Module\Contract\BootstrapPolicyProvidingModuleInterface;
use Kernel\Policy\ActionPolicy;
use Kernel\Policy\PolicyDecision;

final readonly class MarketingDomainModule implements DomainModuleInterface,ActionOwningModuleInterface,
    PolicyProvidingModuleInterface,BootstrapPolicyProvidingModuleInterface
{
    public function __construct(private MarketingAnalystRunHandler $analyst,private ?MarketingPaidLifecycleHandler $paid=null,private ?MarketingAttributionSweepHandler $sweep=null,private ?MarketingOptimizationReviewHandler $review=null,private ?MarketingOptimizationLearningHandler $learning=null,private ?MarketingOptimizationReplacementHandler $replacement=null){}
    public function name():string{return 'marketing';}
    public function actionTypes():array{return [MarketingAnalystRunHandler::TYPE,...($this->paid===null?[]:MarketingPaidLifecycleHandler::TYPES),...($this->sweep===null?[]:[MarketingAttributionSweepHandler::TYPE]),...($this->review===null?[]:[MarketingOptimizationReviewHandler::TYPE]),...($this->learning===null?[]:[MarketingOptimizationLearningHandler::TYPE]),...($this->replacement===null?[]:[MarketingOptimizationReplacementHandler::TYPE])];}
    public function actionHandlers():array{return [$this->analyst,...($this->paid===null?[]:[$this->paid]),...($this->sweep===null?[]:[$this->sweep]),...($this->review===null?[]:[$this->review]),...($this->learning===null?[]:[$this->learning]),...($this->replacement===null?[]:[$this->replacement])];}
    public function policies(string $organizationId):array{
        $policies=[new ActionPolicy(substr(hash('sha256',$organizationId.':marketing-analyst-approved-v1'),0,32),
            $organizationId,MarketingAnalystRunHandler::TYPE,[],PolicyDecision::ApprovalRequired,10,
            'Hermes advisory execution requires independently approved canonical Workflow Action.')];
        if($this->paid!==null)foreach(MarketingPaidLifecycleHandler::TYPES as $type){
            $policies[]=new ActionPolicy(substr(hash('sha256',$organizationId.':'.$type.':v1'),0,32),$organizationId,$type,[],PolicyDecision::ApprovalRequired,10,
                'Independent canonical paid Marketing Action approval required.');
        }
        if($this->sweep!==null)$policies[]=new ActionPolicy(substr(hash('sha256',$organizationId.':'.MarketingAttributionSweepHandler::TYPE.':v1'),0,32),
            $organizationId,MarketingAttributionSweepHandler::TYPE,[],PolicyDecision::ApprovalRequired,10,
            'Independent canonical attribution sweep Action approval required.');
        if($this->review!==null)$policies[]=new ActionPolicy(substr(hash('sha256',$organizationId.':'.MarketingOptimizationReviewHandler::TYPE.':v1'),0,32),
            $organizationId,MarketingOptimizationReviewHandler::TYPE,[],PolicyDecision::ApprovalRequired,10,
            'Independent canonical optimization review Action approval required.');
        if($this->learning!==null)$policies[]=new ActionPolicy(substr(hash('sha256',$organizationId.':'.MarketingOptimizationLearningHandler::TYPE.':v1'),0,32),
            $organizationId,MarketingOptimizationLearningHandler::TYPE,[],PolicyDecision::ApprovalRequired,10,
            'Independent canonical optimization learning Action approval required.');
        if($this->replacement!==null)$policies[]=new ActionPolicy(substr(hash('sha256',$organizationId.':'.MarketingOptimizationReplacementHandler::TYPE.':v1'),0,32),
            $organizationId,MarketingOptimizationReplacementHandler::TYPE,[],PolicyDecision::ApprovalRequired,10,
            'Independent canonical optimization replacement Action approval required.');
        return $policies;
    }
    public function bootstrapPolicies():array{return $this->policies('default');}
}
