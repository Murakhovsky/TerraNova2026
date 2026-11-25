<?php
declare(strict_types=1);

namespace Domains\Sales\Application\UseCase;

use Domains\Sales\Application\Contract\DealRepositoryInterface;
use Domains\Sales\Application\Contract\DealStageRepositoryInterface;
use Domains\Sales\Application\DTO\OperationResult;
use Domains\Sales\Automation\Event\ClientCaseChanged;
use Domains\Sales\Model\DealChangeSet;
use Kernel\Event\EventBus;
use Kernel\Event\EventMetadata;
use Kernel\Transaction\Contract\TransactionManagerInterface;
use RuntimeException;

final readonly class SetDealCommercialTerms
{
    public function __construct(private DealRepositoryInterface $deals,private DealStageRepositoryInterface $state,
        private EventBus $events,private TransactionManagerInterface $transactions) {}

    public function execute(string $org,string $id,string $value,string $currency,string $actorId,string $correlation):OperationResult
    {
        $changes=DealChangeSet::fromArray(['deal_value'=>$value,'currency'=>$currency]);
        return $this->transactions->transactional(function()use($org,$id,$value,$currency,$actorId,$correlation,$changes):OperationResult{
            $row=$this->state->getForStageChange($org,$id);
            if ($row===null) return OperationResult::failure('Deal not found.');
            $before=['deal_value'=>$row['deal_value']===null?null:(string)$row['deal_value'],'currency'=>(string)$row['currency']];
            if ($before===['deal_value'=>$value,'currency'=>$currency]) return OperationResult::success($id,['changed'=>false]);
            if (in_array((string)$row['status'],['closed','lost'],true)) return OperationResult::failure('Terminal deal commercial terms are immutable.');
            $result=$this->deals->update($org,$id,$changes);
            if (!$result->successful) throw new \DomainException('Commercial update rejected.');
            $after=$this->state->getForStageChange($org,$id);
            if ($after===null || (string)$after['deal_value']!==$value || (string)$after['currency']!==$currency) throw new RuntimeException('Commercial terms native readback failed.');
            $this->events->publish(ClientCaseChanged::create(bin2hex(random_bytes(16)),$org,$id,[
                'deal_value'=>['from'=>$before['deal_value'],'to'=>$value],
                'currency'=>['from'=>$before['currency'],'to'=>$currency],
            ],new EventMetadata($correlation,null,'USER',$actorId)));
            return OperationResult::success($id,['changed'=>true]);
        });
    }
}
