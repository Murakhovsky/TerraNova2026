<?php
declare(strict_types=1);

namespace Kernel\Rule\Service;

use Kernel\Event\Contract\EventHandlerInterface;
use Kernel\Event\DomainEvent;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Module\DomainModuleRegistry;
use Kernel\Rule\Contract\ActionProposalSinkInterface;
use Kernel\Rule\Contract\RuleContextProviderInterface;
use Kernel\Rule\Contract\RuleEvaluationRepositoryInterface;
use Kernel\Rule\Contract\RuleRepositoryInterface;

final readonly class RuleEngineEventHandler implements EventHandlerInterface
{
    public function __construct(
        private RuleRepositoryInterface $rules,
        private RuleContextProviderInterface $contexts,
        private RuleEvaluationRepositoryInterface $evaluations,
        private ActionProposalSinkInterface $actions,
        private DeterministicProcessEngine $engine,
        private ?DomainModuleRegistry $domains = null,
        private ?ActiveModuleResolver $modules = null,
    ) {}

    public function handle(DomainEvent $event): void
    {
        $moduleId = $this->domains?->ownerOfEvent($event->type);
        if ($moduleId !== null && $this->modules !== null && !$this->modules->isEnabled($event->organizationId, $moduleId)) {
            return;
        }

        $rules = $this->rules->activeFor($event->organizationId, $event->type);
        if ($rules === []) {
            return;
        }

        $context = $this->contexts->contextFor($event);
        foreach ($this->engine->evaluate($event, $context, $rules) as $evaluation) {
            $this->evaluations->save($event, $evaluation, $context);
            if ($evaluation->matched) {
                foreach ($evaluation->proposals as $proposal) {
                    $this->actions->accept($event, $proposal);
                }
            }
        }
    }
}
