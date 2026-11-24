<?php
declare(strict_types=1);

namespace App\Application\Sales\Command;

use DomainException;
use Domains\Sales\Application\Contract\SalesMutationReceiptRepositoryInterface;
use Domains\Sales\Application\Contract\SalesProposalLeadReadModelInterface;
use Domains\Sales\Application\Contract\SalesWriteServiceFactoryInterface;
use Domains\Sales\Domain\Policy\LeadLifecyclePolicy;
use Kernel\Application\Command\CommandHandlerInterface;
use Kernel\Transaction\Contract\TransactionManagerInterface;
use RuntimeException;

final readonly class ManageSalesLeadStateCommandHandler implements CommandHandlerInterface
{
    public function __construct(private SalesWriteServiceFactoryInterface $writes,
        private SalesProposalLeadReadModelInterface $leads,private SalesMutationReceiptRepositoryInterface $receipts,
        private TransactionManagerInterface $transactions) {}

    public function __invoke(ManageSalesLeadStateCommand $command):array
    {
        $org=$command->organizationId->value();$id=$command->leadId;$key=trim($command->idempotencyKey);
        if ($id<1 || $command->actorId<1 || $key==='' || strlen($key)>191
            || !LeadLifecyclePolicy::allows($command->expectedStatus,$command->targetStatus)) throw new DomainException('Invalid lead lifecycle transition.');
        $operation='lead.state.manage';
        $hash=hash('sha256',json_encode([$command->actorId,$id,$command->expectedStatus,$command->targetStatus],JSON_THROW_ON_ERROR));
        return $this->transactions->transactional(function()use($command,$org,$id,$key,$operation,$hash):array{
            $before=$this->leads->lead($org,$id);
            if ($before===null || ($before['organization_id']??null)!==$org || !in_array($before['id']??null,[$id,(string)$id],true)) throw new DomainException('Tenant-owned lead not found.');
            $pending='pending:'.bin2hex(random_bytes(16));
            if (!$this->receipts->claim($org,$operation,$key,$pending)) {
                $existing=$this->receipts->find($org,$operation,$key);
                if ($existing===null || !hash_equals('done:'.$hash,$existing) || ($before['status']??null)!==$command->targetStatus) throw new DomainException('Lead state receipt conflict or native state has changed.');
                return ['lead_id'=>$id,'status'=>$command->targetStatus,'replayed'=>true];
            }
            $result=$this->writes->forOrganization($org)->updateLead($id,['expected_status'=>$command->expectedStatus,'status'=>$command->targetStatus],$command->actorId,$command->correlationId);
            if (!$result->ok || !in_array($result->code,['updated','already_applied'],true)) throw new DomainException('Lead transition rejected: '.$result->code);
            $after=$this->leads->lead($org,$id);
            if ($after===null || ($after['organization_id']??null)!==$org || !in_array($after['id']??null,[$id,(string)$id],true) || ($after['status']??null)!==$command->targetStatus) throw new RuntimeException('Lead lifecycle readback failed.');
            if (!$this->receipts->complete($org,$operation,$key,$pending,'done:'.$hash)) throw new RuntimeException('Lead lifecycle receipt failed.');
            return ['lead_id'=>$id,'status'=>$command->targetStatus,'replayed'=>$result->code==='already_applied'];
        });
    }
}
