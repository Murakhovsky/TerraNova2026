<?php

declare(strict_types=1);

namespace App\Web\Experience\AI;

use App\Web\Experience\Action\UIAction;
use Kernel\Agent\AgentActionProjection;

final readonly class AgentActionView
{
    /** @param list<UIAction> $uiActions */
    public function __construct(
        public AgentActionProjection $action,
        public string $stage,
        public array $uiActions = [],
    ) {
    }
}
