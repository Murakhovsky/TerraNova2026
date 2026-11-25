<?php
declare(strict_types=1);

namespace App\Application\Integration\Command;

use DomainException;
use Domains\Sales\Application\Contract\CrmInboxRecoveryRepositoryInterface;
use Domains\Sales\Application\UseCase\ProcessCrmInbox;
use Domains\Sales\Application\Service\SalesMutationGuard;
use Domains\Sales\Application\Contract\SalesMutationReceiptRepositoryInterface;
use RuntimeException;
use Kernel\Application\Command\CommandHandlerInterface;

final readonly class ProcessCrmInboxNowCommandHandler implements CommandHandlerInterface
{
    public function __construct(private CrmInboxRecoveryRepositoryInterface $inbox,private ProcessCrmInbox $processor,
        private SalesMutationGuard $guard,private SalesMutationReceiptRepositoryInterface $receipts) {}

    public function __invoke(ProcessCrmInboxNowCommand $command): array
    {
        if ($command->actorId<1 || trim($command->idempotencyKey)==='' || strlen($command->idempotencyKey)>191
            || preg_match('/^[a-f0-9]{32}$/D',$command->inboxId)!==1
            || preg_match('/^[a-f0-9]{64}$/D',$command->revision)!==1) throw new DomainException('Invalid CRM processing request.');
        $org=$command->organizationId->value();
        $key=$command->idempotencyKey;
        $done='done:'.hash('sha256',json_encode([$command->actorId,$command->inboxId,$command->revision],JSON_THROW_ON_ERROR));
        $authorize=fn()=>$this->guard->run($org,$command->actorId,
            fn()=>($command->authorize!==null?($command->authorize)():null),permission:'sales.admin.integrations.manage');
        $authorize();
        $existing=$this->receipts->find($org,'crm.inbox.process',$key);
        if($existing!==null&&!hash_equals($done,$existing))throw new DomainException('CRM processing key conflict.');
        $row=$this->inbox->status($org,$command->inboxId);
        if ($row===null || !$row['signature_verified']) throw new DomainException('Verified CRM inbox item not found.');
        if ($row['status']==='COMPLETED') {
            if($command->authorize!==null&&$existing!==$done)
                throw new DomainException('Completed inbox has no receipt for this approved Action.');
            return $this->receipt($command,true);
        }
        if (!hash_equals($row['revision'],$command->revision)) throw new DomainException('CRM observation is stale.');
        $processed=$this->processor->execute($org,$command->inboxId,'operator:'.$command->actorId,$command->revision,$authorize,
            function()use($org,$key,$done):void{
                $pending='pending:'.bin2hex(random_bytes(16));
                if(!$this->receipts->claim($org,'crm.inbox.process',$key,$pending)
                    ||!$this->receipts->complete($org,'crm.inbox.process',$key,$pending,$done))
                    throw new RuntimeException('CRM processing completion receipt conflict.');
            });
        if(!$processed&&$command->authorize!==null)throw new DomainException('Approved CRM Action did not own processing.');
        $row=$this->inbox->status($org,$command->inboxId);
        if ($row===null || !$row['signature_verified'] || $row['status']!=='COMPLETED') {
            throw new DomainException('CRM item is not completed; inspect native status before retry.');
        }
        return $this->receipt($command,!$processed);
    }

    private function receipt(ProcessCrmInboxNowCommand $command,bool $replayed):array
    {
        return ['inbox_id'=>$command->inboxId,'status'=>'COMPLETED','replayed'=>$replayed];
    }
}
