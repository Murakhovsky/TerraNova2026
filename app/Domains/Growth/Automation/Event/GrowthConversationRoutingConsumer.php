<?php
declare(strict_types=1);

namespace Domains\Growth\Automation\Event;

use Domains\Growth\Application\Contract\GrowthConversationRoutingBoundary;
use InvalidArgumentException;
use Kernel\Event\Contract\DurableEventConsumerInterface;
use Kernel\Event\DomainEvent;
use Kernel\Module\ActiveModuleResolver;

final readonly class GrowthConversationRoutingConsumer implements DurableEventConsumerInterface
{
    public function __construct(
        private GrowthConversationRoutingBoundary $routing,
        private ActiveModuleResolver $modules,
    ) {}

    public function consumerName():string{return 'growth.conversation-routing.v1';}

    public function handle(DomainEvent $event):void
    {
        if($event->type!==GrowthEventType::ENGAGEMENT_RESPONSE_CLASSIFIED)return;
        if(!$this->modules->isEnabled($event->organizationId,'growth'))return;

        $responseId=$event->payload['response_id']??null;
        if(!is_string($responseId)||trim($responseId)===''){
            throw new InvalidArgumentException('Growth response classification event is missing response_id.');
        }
        $this->routing->routeResponse($event->organizationId,$responseId,$event->metadata->correlationId);
    }
}
