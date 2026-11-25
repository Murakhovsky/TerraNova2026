<?php
declare(strict_types=1);
namespace Domains\Finance\Bootstrap;

use Domains\Finance\Automation\Action\FinanceBudgetActionHandler;
use Domains\Finance\Automation\Action\FinanceOrderPaymentActionHandler;
use Domains\Finance\Automation\Action\FinancePaidSettlementHandler;
use Domains\Finance\Application\Service\ApprovedFinancePaidSettlementService;
use Kernel\Module\Contract\ActionOwningModuleInterface;
use Kernel\Module\Contract\BootstrapPolicyProvidingModuleInterface;
use Kernel\Module\Contract\PolicyProvidingModuleInterface;
use Kernel\Module\DomainModuleInterface;
use Kernel\Policy\ActionPolicy;
use Kernel\Policy\PolicyDecision;

/** Finance remains the only budget writer; all financial Actions require independent Approval. */
final readonly class FinanceDomainModule implements DomainModuleInterface,
    ActionOwningModuleInterface,PolicyProvidingModuleInterface,BootstrapPolicyProvidingModuleInterface
{
    public function __construct(private FinanceBudgetActionHandler $handler,private ?FinanceOrderPaymentActionHandler $payments=null,private ?FinancePaidSettlementHandler $settlement=null){}
    public function name():string{return 'finance';}
    public function actionTypes():array
    {
        return [
            FinanceBudgetActionHandler::ACTIVATE,
            FinanceBudgetActionHandler::RESERVE,
            FinanceBudgetActionHandler::RELEASE,
            FinanceBudgetActionHandler::FREEZE,
            ...($this->payments===null?[]:[FinanceOrderPaymentActionHandler::REGISTER,FinanceOrderPaymentActionHandler::RECONCILE]),
            ...($this->settlement===null?[]:[ApprovedFinancePaidSettlementService::TYPE]),
        ];
    }
    public function actionHandlers():array{return array_values(array_filter([$this->handler,$this->payments,$this->settlement]));}
    public function policies(string $organizationId):array
    {
        $policies=[];
        foreach($this->actionTypes() as $type){
            $id=$organizationId==='default'
                ?str_replace('.','-',$type).'-approval-v1'
                :substr(hash('sha256',$organizationId.':'.$type.':approval-v1'),0,32);
            $policies[]=new ActionPolicy(
                $id,$organizationId,$type,[],PolicyDecision::ApprovalRequired,10,
                'Independent Finance approval required',
                'Finance: operation must be independently approved before any financial mutation'
            );
        }
        return $policies;
    }
    public function bootstrapPolicies():array{return $this->policies('default');}
}

