<?php
declare(strict_types=1);
namespace Domains\Sales\Application\UseCase;
use Domains\Sales\Application\Contract\SalesOperationRepositoryInterface;
use Domains\Sales\Application\DTO\OperationResult;
use Kernel\Transaction\Contract\TransactionManagerInterface;
final readonly class ScheduleSalesMeeting
{public function __construct(private SalesOperationRepositoryInterface $operations,private TransactionManagerInterface $transactions){}public function execute(string $organizationId,string $dealId,string $title,\DateTimeImmutable $at,string $idempotencyKey,array $metadata=[]):OperationResult{return $this->transactions->transactional(function()use($organizationId,$dealId,$title,$at,$idempotencyKey,$metadata){$id=$this->operations->scheduleMeeting($organizationId,$dealId,$title,$at,$idempotencyKey,$metadata);return OperationResult::success($id,['duplicate'=>$id===null,'scheduled_at'=>$at->format(DATE_ATOM)]);});}}
