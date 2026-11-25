<?php
declare(strict_types=1);

namespace App\Application\Sales\Command;

use DomainException;
use Domains\Sales\Application\Contract\DealStageRepositoryInterface;
use Domains\Sales\Application\Contract\SalesMutationReceiptRepositoryInterface;
use Domains\Sales\Application\DTO\ChangeDealStageCommand;
use Domains\Sales\Application\Support\DealStateSnapshot;
use Domains\Sales\Application\UseCase\AssignDealOwner;
use Domains\Sales\Application\UseCase\ChangeDealStage;
use Domains\Sales\Application\UseCase\SetDealCommercialTerms;
use Domains\Sales\Model\SalesCurrency;
use Kernel\Application\Command\CommandHandlerInterface;
use Kernel\Transaction\Contract\TransactionManagerInterface;
use RuntimeException;

/** One orchestrator for typed operator contracts; native use cases retain all writes and events. */
final readonly class ManageSalesDealCommandHandler implements CommandHandlerInterface
{
    public function __construct(private DealStageRepositoryInterface $deals,private AssignDealOwner $owners,
        private ChangeDealStage $stages,private SetDealCommercialTerms $commercial,
        private SalesMutationReceiptRepositoryInterface $receipts,private TransactionManagerInterface $transactions) {}

    public function __invoke(ManageSalesDealCommand $command):array
    {
        $org=$command->organizationId->value();$input=$command->input;
        $id=$input['deal_id']??null;$revision=$input['expected_revision']??null;
        $key=trim($command->idempotencyKey);$op=$command->operation;
        if ($command->actorId<1 || !is_int($id) || $id<1 || !is_string($revision)
            || preg_match('/^[a-f0-9]{64}$/D',$revision)!==1 || $key==='' || strlen($key)>191) throw new DomainException('Invalid deal mutation identity.');
        $this->validate($op,$input);
        ksort($input);
        $fingerprint=hash('sha256',json_encode([$command->actorId,$op,$input],JSON_THROW_ON_ERROR));
        $operation='deal.'.$op;
        return $this->transactions->transactional(function()use($command,$org,$id,$revision,$key,$op,$input,$fingerprint,$operation):array{
            $row=$this->deals->getForStageChange($org,(string)$id);
            if ($row===null) throw new DomainException('Tenant-owned deal not found.');
            $before=DealStateSnapshot::fromNative($row,$org,$id);
            $pending='pending:'.bin2hex(random_bytes(16));
            if (!$this->receipts->claim($org,$operation,$key,$pending)) {
                $receipt=$this->receipts->find($org,$operation,$key);
                if ($receipt===null || preg_match('/^done:([a-f0-9]{64}):([01])$/D',$receipt,$parts)!==1
                    || !hash_equals($fingerprint,$parts[1]) || !$this->matches($op,$input,$before)) throw new DomainException('Deal receipt conflict or native state changed after original operation.');
                return ['state'=>$before,'changed'=>$parts[2]==='1','replayed'=>true];
            }
            if (!hash_equals($before['revision'],$revision)) throw new DomainException('Deal observation is stale.');
            if ($op==='owner.assign') {
                $result=$this->owners->execute($org,(string)$id,$input['owner_id'],$command->correlationId,'USER',(string)$command->actorId);
                if (!$result->successful) throw new DomainException($result->error??'Owner assignment denied.');
                $changed=($result->data['changed']??false)===true;
            } elseif ($op==='commercial.set') {
                $result=$this->commercial->execute($org,(string)$id,$input['deal_value'],$input['currency'],(string)$command->actorId,$command->correlationId);
                if (!$result->successful) throw new DomainException($result->error??'Commercial terms rejected.');
                $changed=($result->data['changed']??false)===true;
            } else {
                $result=$this->stages->execute(new ChangeDealStageCommand($org,(string)$id,$input['target_stage_id'],'USER',(string)$command->actorId,
                    $command->correlationId,$input['lost_reason_id']??null,null,$before['stage_id'],$input['outcome']??null,$command->approvedAction));
                if (!$result->successful) throw new DomainException($result->reason??'Stage transition denied.');
                $changed=$result->changed;
            }
            $row=$this->deals->getForStageChange($org,(string)$id);
            if ($row===null) throw new RuntimeException('Deal native readback failed.');
            $after=DealStateSnapshot::fromNative($row,$org,$id);
            if (!$this->matches($op,$input,$after)) throw new RuntimeException('Deal mutation differs from native result.');
            if (!$this->receipts->complete($org,$operation,$key,$pending,'done:'.$fingerprint.':'.($changed?'1':'0'))) throw new RuntimeException('Deal receipt completion failed.');
            return ['state'=>$after,'changed'=>$changed,'replayed'=>false];
        });
    }

    private function validate(string $op,array $input):void
    {
        $fields=match($op){
            'owner.assign'=>['owner_id'],
            'commercial.set'=>['deal_value','currency'],
            'stage.manage'=>['target_stage_id'],
            'outcome.record'=>['target_stage_id','outcome','lost_reason_id'],
            default=>throw new DomainException('Unknown deal operation.'),
        };
        if (array_diff(array_keys($input),['deal_id','expected_revision',...$fields])!==[]) throw new DomainException('Unknown deal input.');
        $validId=static fn(mixed $v):bool=>is_string($v)&&preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$v)===1;
        $ok=match($op){
            'owner.assign'=>is_int($input['owner_id']??null)&&$input['owner_id']>0,
            'commercial.set'=>is_string($input['deal_value']??null)&&preg_match('/^(0|[1-9][0-9]{0,11})\.[0-9]{2}$/D',$input['deal_value'])===1
                &&is_string($input['currency']??null)&&SalesCurrency::accepts($input['currency']),
            'stage.manage'=>$validId($input['target_stage_id']??null),
            'outcome.record'=>$validId($input['target_stage_id']??null)
                &&(($input['outcome']??null)==='lost'?$validId($input['lost_reason_id']??null):(($input['outcome']??null)==='won'&&!array_key_exists('lost_reason_id',$input))),
        };
        if (!$ok) throw new DomainException('Invalid deal operation fields.');
    }

    private function matches(string $op,array $input,array $state):bool
    {
        return match($op){
            'owner.assign'=>$state['owner_id']===$input['owner_id'],
            'commercial.set'=>$state['deal_value']===$input['deal_value']&&$state['currency']===$input['currency'],
            'stage.manage'=>$state['stage_id']===$input['target_stage_id']&&!in_array($state['status'],['closed','lost'],true),
            'outcome.record'=>$state['stage_id']===$input['target_stage_id']&&$state['status']===($input['outcome']==='won'?'closed':'lost')
                &&($input['outcome']==='won'||$state['lost_reason_id']===$input['lost_reason_id']),
            default=>false,
        };
    }
}
