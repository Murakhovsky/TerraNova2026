<?php
declare(strict_types=1);

namespace Kernel\Workflow\Model;

use InvalidArgumentException;
use Kernel\Workflow\Model\Step\DecisionStep;
use Kernel\Workflow\Model\Step\Step;

final readonly class WorkflowDefinition
{
    /** @var array<string,Step> */
    private array $stepMap;
    /** @var array<string,list<Transition>> */
    private array $outgoing;

    /**
     * @param list<Step> $steps
     * @param list<Transition> $transitions
     */
    public function __construct(
        public string $id,
        public string $version,
        public string $name,
        public string $entryStepId,
        public array $steps,
        public array $transitions = [],
    ) {
        if (preg_match('/^[a-z][a-z0-9_.-]*$/', $id) !== 1) throw new InvalidArgumentException('Workflow definition requires a canonical id.');
        if (trim($version) === '' || trim($name) === '') throw new InvalidArgumentException('Workflow definition requires version and name.');
        if ($steps === []) throw new InvalidArgumentException('Workflow definition requires at least one step.');

        $stepMap = [];
        foreach ($steps as $step) {
            if (!$step instanceof Step) throw new InvalidArgumentException('Workflow definition contains an invalid step.');
            if (isset($stepMap[$step->id])) throw new InvalidArgumentException('Duplicate workflow step: ' . $step->id);
            $stepMap[$step->id] = $step;
        }
        if (!isset($stepMap[$entryStepId])) throw new InvalidArgumentException('Workflow entry step does not exist: ' . $entryStepId);

        $outgoing = array_fill_keys(array_keys($stepMap), []);
        $edgeKeys = [];
        $defaultBySource = [];
        foreach ($transitions as $transition) {
            if (!$transition instanceof Transition) throw new InvalidArgumentException('Workflow definition contains an invalid transition.');
            if (!isset($stepMap[$transition->from], $stepMap[$transition->to])) {
                throw new InvalidArgumentException(sprintf('Workflow transition %s -> %s references an unknown step.', $transition->from, $transition->to));
            }
            $conditionKey = $transition->condition === null
                ? 'default'
                : $transition->condition->path . ':' . $transition->condition->operator->value . ':' . json_encode($transition->condition->value, JSON_THROW_ON_ERROR);
            $key = $transition->from . '->' . $transition->to . ':' . ($transition->label ?? '') . ':' . $conditionKey;
            if (isset($edgeKeys[$key])) throw new InvalidArgumentException('Duplicate workflow transition: ' . $key);
            $edgeKeys[$key] = true;
            if ($transition->condition === null) {
                if (isset($defaultBySource[$transition->from])) throw new InvalidArgumentException('Workflow step may have at most one unconditional transition: ' . $transition->from);
                $defaultBySource[$transition->from] = true;
            }
            $outgoing[$transition->from][] = $transition;
        }

        foreach ($stepMap as $step) {
            if ($step instanceof DecisionStep && $outgoing[$step->id] === []) {
                throw new InvalidArgumentException('DecisionStep requires at least one outgoing transition: ' . $step->id);
            }
        }

        $reachable = [];
        $queue = [$entryStepId];
        while ($queue !== []) {
            $current = array_shift($queue);
            if (isset($reachable[$current])) continue;
            $reachable[$current] = true;
            foreach ($outgoing[$current] as $transition) $queue[] = $transition->to;
        }
        if (count($reachable) !== count($stepMap)) throw new InvalidArgumentException('Workflow definition contains unreachable steps.');

        $this->stepMap = $stepMap;
        $this->outgoing = $outgoing;
    }

    public function step(string $id): Step
    {
        return $this->stepMap[$id] ?? throw new InvalidArgumentException('Unknown workflow step: ' . $id);
    }

    /** @return list<Transition> */
    public function outgoing(string $stepId): array
    {
        if (!array_key_exists($stepId, $this->outgoing)) throw new InvalidArgumentException('Unknown workflow step: ' . $stepId);
        return $this->outgoing[$stepId];
    }
}
