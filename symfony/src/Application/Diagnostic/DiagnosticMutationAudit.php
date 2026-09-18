<?php
declare(strict_types=1);
namespace App\Application\Diagnostic;
use DateTimeImmutable;
use Kernel\Audit\AuditEntry;
use Kernel\Audit\Contract\AuditRepositoryInterface;
final readonly class DiagnosticMutationAudit
{
    public function __construct(private AuditRepositoryInterface $audit){}
    public function record(string $organizationId,int $actorId,string $correlationId,string $action,string $subjectId,array $data=[]):void
    {
        $this->audit->append(new AuditEntry(bin2hex(random_bytes(16)),$organizationId,'DIAGNOSTIC_MUTATION','USER',(string)$actorId,'diagnostic',$subjectId,null,['action'=>$action]+$data,$correlationId,new DateTimeImmutable()));
    }
}
