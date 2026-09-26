<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

use Domains\Growth\Application\DTO\GrowthConversationRouteRequest;
use Domains\Growth\Application\DTO\GrowthConversationRoutingTargetResult;

interface GrowthConversationRoutingTargetInterface
{
    public function route():string;

    public function accept(
        GrowthConversationRouteRequest $request,
        int $actorId,
        string $correlationId,
        string $idempotencyKey,
    ):GrowthConversationRoutingTargetResult;
}
