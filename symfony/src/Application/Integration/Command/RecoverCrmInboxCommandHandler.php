<?php
declare(strict_types=1);

namespace App\Application\Integration\Command;

use App\Application\Integration\IntegrationMutationAudit;
use DomainException;
use Domains\Sales\Application\Contract\CrmInboxRecoveryRepositoryInterface;
use Domains\Sales\Application\Contract\SalesMutationReceiptRepositoryInterface;
use Kernel\Application\Command\CommandHandlerInterface;
use Kernel\Transaction\Contract\TransactionManagerInterface;
use RuntimeException;

/** Requeue only. The existing sweep/worker owns actual processing and completion. */
final readonly class RecoverCrmInboxCommandHandler implements CommandHandlerInterface
{
    private const OPERATION='crm.inbox.recover';
    public const REASONS=['mapping_repaired','configuration_repaired','transient_failure_resolved'];

    public function __construct(private CrmInboxRecoveryRepositoryInterface $inbox,
        private SalesMutationReceiptRepositoryInterface $receipts,
        private TransactionManagerInterface $transactions,private IntegrationMutationAudit $audit) {}

    public function __invoke(RecoverCrmInboxCommand $command): array
    {
        $org=$command->organizationId->value();
        $key=trim($command->idempotencyKey);
        if ($command->actorId<1 || $key==='' || strlen($key)>191
            || preg_match('/^[a-f0-9]{32}$/D',$command->inboxId)!==1
            || preg_match('/^[a-f0-9]{64}$/D',$command->revision)!==1
            || !in_array($command->reason,self::REASONS,true)) throw new DomainException('Invalid CRM recovery request.');
        $hash=hash('sha256',json_encode([$command->actorId,$command->inboxId,$command->revision,$command->reason],JSON_THROW_ON_ERROR));
        $done='done:'.$hash;
        return $this->transactions->transactional(function()use($command,$org,$key,$done):array{
            $pending='pending:'.bin2hex(random_bytes(16));
            if (!$this->receipts->claim($org,self::OPERATION,$key,$pending)) {
                $existing=$this->receipts->find($org,self::OPERATION,$key);
                if ($existing===null || !hash_equals($done,$existing)
                    || $this->inbox->status($org,$command->inboxId,true)===null) throw new DomainException('CRM recovery idempotency conflict.');
                return ['inbox_id'=>$command->inboxId,'requeued'=>true,'replayed'=>true];
            }
            if (!$this->inbox->requeueDead($org,$command->inboxId,$command->revision)) {
                throw new DomainException('CRM recovery requires a verified DEAD item at the observed revision.');
            }
            $row=$this->inbox->status($org,$command->inboxId,true);
            if ($row===null || $row['status']!=='RECEIVED' || $row['attempts']!==0) throw new RuntimeException('CRM recovery native readback failed.');
            $this->audit->user($org,$command->actorId,$command->correlationId,'sales.crm.inbox.recover',$command->inboxId,
                ['reason_code'=>$command->reason,'prior_revision'=>$command->revision]);
            if (!$this->receipts->complete($org,self::OPERATION,$key,$pending,$done)) throw new RuntimeException('CRM recovery receipt failed.');
            return ['inbox_id'=>$command->inboxId,'requeued'=>true,'replayed'=>false];
        });
    }
}
