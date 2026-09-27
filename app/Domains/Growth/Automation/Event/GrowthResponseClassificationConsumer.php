<?php
declare(strict_types=1);

namespace Domains\Growth\Automation\Event;

use Domains\Growth\Application\Contract\GrowthResponseClassificationBoundary;
use InvalidArgumentException;
use Kernel\Event\Contract\DurableEventConsumerInterface;
use Kernel\Event\DomainEvent;
use Kernel\Module\ActiveModuleResolver;

final readonly class GrowthResponseClassificationConsumer implements DurableEventConsumerInterface
{
    public function __construct(
        private GrowthResponseClassificationBoundary $classification,
        private ActiveModuleResolver $modules,
    ) {}

    public function consumerName():string{return 'growth.response-classification.v1';}

    public function handle(DomainEvent $event):void
    {
        if($event->type!==GrowthEventType::ENGAGEMENT_RESPONSE_RECEIVED)return;
        if(!$this->modules->isEnabled($event->organizationId,'growth'))return;

        $responseId=$event->payload['response_id']??null;
        if(!is_string($responseId)||trim($responseId)===''){
            throw new InvalidArgumentException('Growth response event is missing response_id.');
        }

        $this->classification->classifyResponse(
            $event->organizationId,$responseId,$event->metadata->correlationId,
        );
    }
}
