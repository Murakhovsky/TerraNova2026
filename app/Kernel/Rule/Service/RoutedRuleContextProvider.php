<?php
declare(strict_types=1);

namespace Kernel\Rule\Service;

use Kernel\Event\DomainEvent;
use Kernel\Module\DomainModuleRegistry;
use Kernel\Rule\Contract\RuleContextProviderInterface;

final readonly class RoutedRuleContextProvider implements RuleContextProviderInterface
{
    public function __construct(private DomainModuleRegistry $domains)
    {
    }

    public function contextFor(DomainEvent $event): array
    {
        return $this->domains->ruleContextProviderFor($event->type)->contextFor($event);
    }
}
