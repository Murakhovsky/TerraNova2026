<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Compatibility;

use Domains\Sales\Domain\Activity\ActivityType;
use Domains\Sales\Domain\Lead\LeadStatus;
use Domains\Sales\Domain\Opportunity\OpportunityStatus;
use Domains\Sales\Domain\Pipeline\Pipeline;
use Domains\Sales\Domain\Pipeline\PipelineId;
use Domains\Sales\Domain\Pipeline\PipelineStage;
use Domains\Sales\Domain\Pipeline\PipelineStageId;
use Domains\Sales\Model\ClientCaseStatus;
use Domains\Sales\Model\LeadStatus as LegacyLeadStatus;
use Domains\Sales\Model\PipelineDefinition;
use Domains\Sales\Model\PipelineStageDefinition;
use Domains\Sales\Model\SalesActivityType;
use Kernel\Shared\Domain\OrganizationId;

final class LegacySalesDomainMapper
{
    public static function leadStatus(LegacyLeadStatus $status): LeadStatus
    {
        return LeadStatus::from($status->value);
    }

    public static function opportunityStatus(ClientCaseStatus $status): OpportunityStatus
    {
        return OpportunityStatus::from($status->value);
    }

    public static function activityType(SalesActivityType $type): ActivityType
    {
        return ActivityType::from($type->value);
    }

    public static function pipeline(PipelineDefinition $pipeline): Pipeline
    {
        return new Pipeline(
            PipelineId::fromString($pipeline->id),
            OrganizationId::fromString($pipeline->organizationId),
            $pipeline->code,
            $pipeline->name,
            array_map(self::pipelineStage(...), $pipeline->stages),
            PipelineStageId::fromString($pipeline->initialStageId),
        );
    }

    private static function pipelineStage(PipelineStageDefinition $stage): PipelineStage
    {
        return new PipelineStage(
            PipelineStageId::fromString($stage->id),
            $stage->code,
            $stage->name,
            $stage->order,
            $stage->isTerminal,
            $stage->isWon,
            $stage->isLost,
            $stage->probabilityDefault,
        );
    }
}
