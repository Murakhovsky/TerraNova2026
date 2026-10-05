<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Audit;

use DateTimeImmutable;
use Kernel\Shared\Domain\OrganizationId;
use Platform\Audit\Model\ActivityRecord;
use Platform\Audit\Model\ActivitySource;
use Platform\Audit\Model\ActivityStatus;
use Platform\Audit\Model\Actor;
use Platform\Audit\Model\ResourceReference;
use Platform\Audit\Service\AuditRecorder;

final readonly class CapitalMarketsAuditTrail
{
    public function __construct(private AuditRecorder $audit){}

    /** @param array<string,mixed> $previous @param array<string,mixed> $next */
    public function record(
        string $organizationId,
        int $actorId,
        CapitalMarketsAuditAction $action,
        CapitalMarketsAuditResourceType $resourceType,
        string $resourceId,
        array $previous,
        array $next,
        string $correlationId,
    ):void{
        $this->audit->record(new ActivityRecord(
            id:'cm_'.bin2hex(random_bytes(16)),
            organizationId:OrganizationId::fromString($organizationId),
            actor:new Actor('user',(string)$actorId),
            action:$action->value,
            resource:new ResourceReference($resourceType->value,$resourceId),
            input:['previous'=>$previous],
            output:['new'=>$next],
            agent:null,tool:null,workflow:null,durationMs:null,cost:null,costUnit:null,
            status:ActivityStatus::SUCCESS,
            error:null,
            correlationId:$correlationId,
            timestamp:new DateTimeImmutable(),
            metadata:['domain'=>'capital_markets'],
            source:ActivitySource::HUMAN,
        ));
    }
}
