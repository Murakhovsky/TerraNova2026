<?php
declare(strict_types=1);

namespace App\Application\Integration\Command;

use DomainException;
use Domains\Sales\Application\Contract\CrmInboxRecoveryRepositoryInterface;
use Domains\Sales\Application\UseCase\ProcessCrmInbox;
use Kernel\Application\Command\CommandHandlerInterface;

final readonly class ProcessCrmInboxNowCommandHandler implements CommandHandlerInterface
{
    public function __construct(private CrmInboxRecoveryRepositoryInterface $inbox,private ProcessCrmInbox $processor) {}

    public function __invoke(ProcessCrmInboxNowCommand $command): array
    {
        if ($command->actorId<1 || trim($command->idempotencyKey)==='' || strlen($command->idempotencyKey)>191
            || preg_match('/^[a-f0-9]{32}$/D',$command->inboxId)!==1
            || preg_match('/^[a-f0-9]{64}$/D',$command->revision)!==1) throw new DomainException('Invalid CRM processing request.');
        $org=$command->organizationId->value();
        $row=$this->inbox->status($org,$command->inboxId);
        if ($row===null || !$row['signature_verified']) throw new DomainException('Verified CRM inbox item not found.');
        if ($row['status']==='COMPLETED') return $this->receipt($command,true);
        if (!hash_equals($row['revision'],$command->revision)) throw new DomainException('CRM observation is stale.');
        $processed=$this->processor->execute($org,$command->inboxId,'operator:'.$command->actorId,$command->revision);
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
