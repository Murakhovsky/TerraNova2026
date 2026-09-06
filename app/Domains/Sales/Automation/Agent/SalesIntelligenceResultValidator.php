<?php
declare(strict_types=1);

namespace Domains\Sales\Automation\Agent;

use Domains\Sales\Model\SalesIntelligenceAssessment;
use Kernel\Agent\AgentDefinition;
use Kernel\Agent\AgentResult;
use Kernel\Agent\Contract\AgentResultValidatorInterface;

final class SalesIntelligenceResultValidator implements AgentResultValidatorInterface
{
    public function validate(AgentResult $result, AgentDefinition $agent): void
    {
        SalesIntelligenceAssessment::fromDecision($result, $agent->allowedActionTypes);
    }
}
