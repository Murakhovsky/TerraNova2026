<?php
declare(strict_types=1);

namespace Kernel\Process;

use InvalidArgumentException;

final readonly class ProcessDefinition
{
    public const SCHEMA_VERSIONS = [4, 5];
    public const CROSS_DOMAIN_SCHEMA = 5;
    public const STATES = ['as-is', 'to-be'];

    /**
     * @param list<string> $outcomes
     * @param list<string> $actors
     * @param list<ProcessStep> $steps
     * @param list<ProcessEdge> $edges
     */
    public function __construct(
        public int $schemaVersion,
        public string $id,
        public string $title,
        public string $domain,
        public string $state,
        public string $workflow,
        public string $trigger,
        public array $outcomes,
        public array $actors,
        public array $steps,
        public array $edges,
    ) {
        if (!in_array($this->schemaVersion, self::SCHEMA_VERSIONS, true)) {
            throw new InvalidArgumentException(sprintf('Process %s must use a supported schema version.', $this->id));
        }
        if (!preg_match('/^[a-z][a-z0-9_.-]*$/', $this->id)) {
            throw new InvalidArgumentException(sprintf('Invalid process id: %s.', $this->id));
        }
        if (!preg_match('/^[a-z][a-z0-9_.-]*$/', $this->domain)) {
            throw new InvalidArgumentException(sprintf('Process %s has invalid domain %s.', $this->id, $this->domain));
        }
        foreach (['title' => $this->title, 'workflow' => $this->workflow, 'trigger' => $this->trigger] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException(sprintf('Process %s requires %s.', $this->id, $field));
            }
        }
        if (!in_array($this->state, self::STATES, true)) {
            throw new InvalidArgumentException(sprintf('Process %s has invalid business state %s.', $this->id, $this->state));
        }
        if ($this->outcomes === [] || $this->actors === [] || $this->steps === []) {
            throw new InvalidArgumentException(sprintf('Process %s requires outcomes, actors and steps.', $this->id));
        }
        if (count(array_unique($this->actors)) !== count($this->actors)) {
            throw new InvalidArgumentException(sprintf('Process %s contains duplicate actors.', $this->id));
        }

        $actors = array_fill_keys($this->actors, true);
        $stepIds = [];
        foreach ($this->steps as $step) {
            if (!$step instanceof ProcessStep) {
                throw new InvalidArgumentException(sprintf('Process %s contains an invalid step.', $this->id));
            }
            if (isset($stepIds[$step->id])) {
                throw new InvalidArgumentException(sprintf('Process %s contains duplicate step %s.', $this->id, $step->id));
            }
            if (!isset($actors[$step->owner])) {
                throw new InvalidArgumentException(sprintf('Process %s step %s owner %s is not a declared actor.', $this->id, $step->id, $step->owner));
            }
            if ($step->domain !== $this->domain) {
                if ($this->schemaVersion < self::CROSS_DOMAIN_SCHEMA) {
                    throw new InvalidArgumentException(sprintf('Process %s step %s crosses into %s but schema v%d+ is required.', $this->id, $step->id, $step->domain, self::CROSS_DOMAIN_SCHEMA));
                }
                $hasContractMapping = false;
                foreach ($step->runtime as $mapping) {
                    if ($mapping instanceof RuntimeMapping && $mapping->type === 'contract') {
                        $hasContractMapping = true;
                        break;
                    }
                }
                if (!$hasContractMapping) {
                    throw new InvalidArgumentException(sprintf('Process %s cross-domain step %s requires a contract mapping.', $this->id, $step->id));
                }
            }
            $stepIds[$step->id] = true;
        }

        $incoming = array_fill_keys(array_keys($stepIds), 0);
        $outgoing = array_fill_keys(array_keys($stepIds), []);
        $edgeKeys = [];
        foreach ($this->edges as $edge) {
            if (!$edge instanceof ProcessEdge) {
                throw new InvalidArgumentException(sprintf('Process %s contains an invalid edge.', $this->id));
            }
            if (!isset($stepIds[$edge->from]) || !isset($stepIds[$edge->to])) {
                throw new InvalidArgumentException(sprintf('Process %s edge %s -> %s references an unknown step.', $this->id, $edge->from, $edge->to));
            }
            $key = $edge->from . '->' . $edge->to . ':' . ($edge->label ?? '');
            if (isset($edgeKeys[$key])) {
                throw new InvalidArgumentException(sprintf('Process %s contains duplicate edge %s.', $this->id, $key));
            }
            $edgeKeys[$key] = true;
            $incoming[$edge->to]++;
            $outgoing[$edge->from][] = $edge->to;
        }

        $roots = array_keys(array_filter($incoming, static fn (int $count): bool => $count === 0));
        $terminals = array_keys(array_filter($outgoing, static fn (array $targets): bool => $targets === []));
        if ($roots === []) {
            throw new InvalidArgumentException(sprintf('Process %s requires at least one root step.', $this->id));
        }
        if ($terminals === []) {
            throw new InvalidArgumentException(sprintf('Process %s requires at least one terminal step.', $this->id));
        }

        $reachable = [];
        $queue = $roots;
        while ($queue !== []) {
            $current = array_shift($queue);
            if (isset($reachable[$current])) continue;
            $reachable[$current] = true;
            foreach ($outgoing[$current] as $next) $queue[] = $next;
        }
        if (count($reachable) !== count($stepIds)) {
            throw new InvalidArgumentException(sprintf('Process %s contains steps unreachable from a root.', $this->id));
        }
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        if (array_key_exists('verification', $data)) {
            throw new InvalidArgumentException('Process verification is derived and must not be authored.');
        }

        $steps = [];
        foreach (is_array($data['steps'] ?? null) ? $data['steps'] : [] as $step) {
            if (!is_array($step)) throw new InvalidArgumentException('Process steps must be objects.');
            $steps[] = ProcessStep::fromArray($step);
        }
        $edges = [];
        foreach (is_array($data['edges'] ?? null) ? $data['edges'] : [] as $edge) {
            if (!is_array($edge)) throw new InvalidArgumentException('Process edges must be objects.');
            $edges[] = ProcessEdge::fromArray($edge);
        }

        return new self(
            schemaVersion: (int)($data['schema_version'] ?? 0),
            id: (string)($data['id'] ?? ''),
            title: (string)($data['title'] ?? ''),
            domain: (string)($data['domain'] ?? ''),
            state: (string)($data['state'] ?? ''),
            workflow: (string)($data['workflow'] ?? ''),
            trigger: (string)($data['trigger'] ?? ''),
            outcomes: array_values(array_filter(is_array($data['outcomes'] ?? null) ? $data['outcomes'] : [], 'is_string')),
            actors: array_values(array_filter(is_array($data['actors'] ?? null) ? $data['actors'] : [], 'is_string')),
            steps: $steps,
            edges: $edges,
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'id' => $this->id,
            'title' => $this->title,
            'domain' => $this->domain,
            'state' => $this->state,
            'workflow' => $this->workflow,
            'trigger' => $this->trigger,
            'outcomes' => $this->outcomes,
            'actors' => $this->actors,
            'steps' => array_map(static fn (ProcessStep $step): array => $step->toArray(), $this->steps),
            'edges' => array_map(static fn (ProcessEdge $edge): array => $edge->toArray(), $this->edges),
        ];
    }
}
