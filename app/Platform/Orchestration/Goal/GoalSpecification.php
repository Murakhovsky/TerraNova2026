<?php
declare(strict_types=1);

namespace Platform\Orchestration\Goal;

use InvalidArgumentException;

/**
 * Immutable versioned business success contract, independent of Workflow state.
 */
final readonly class GoalSpecification
{
    /**
     * @param list<array{id:string,operator:string,expected:int|float|string|bool}> $criteria
     * @param list<string> $allowedCapabilities
     * @param list<string> $requiredApprovals
     * @param array<string,mixed> $riskConstraints
     */
    public function __construct(
        public string $goalId,
        public string $organizationId,
        public string $ownerId,
        public string $desiredResult,
        public array $criteria,
        public array $allowedCapabilities,
        public int $version = 1,
        public ?string $deadline = null,
        public ?int $budgetMinorUnits = null,
        public string $currency = '',
        public array $riskConstraints = [],
        public array $requiredApprovals = [],
        public string $humanInterventionPolicy = 'required_for_external',
    ) {
        foreach (['goalId' => $this->goalId, 'organizationId' => $this->organizationId,
            'ownerId' => $this->ownerId, 'desiredResult' => $this->desiredResult] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException('Missing GoalSpecification field: ' . $field);
            }
        }
        if ($this->version < 1 || $this->criteria === []) {
            throw new InvalidArgumentException('GoalSpecification requires version and measurable criteria.');
        }
        if ($this->budgetMinorUnits !== null && ($this->budgetMinorUnits < 0 || !preg_match('/^[A-Z]{3}$/', $this->currency))) {
            throw new InvalidArgumentException('Goal budget must be nonnegative minor units and have an ISO currency.');
        }
        if ($this->deadline !== null && \DateTimeImmutable::createFromFormat('!Y-m-d', $this->deadline) === false) {
            throw new InvalidArgumentException('Goal deadline must be YYYY-MM-DD.');
        }
        if (!in_array($this->humanInterventionPolicy, ['required_for_external', 'always', 'manual'], true)) {
            throw new InvalidArgumentException('Invalid human intervention policy.');
        }
        $ids = [];
        foreach ($this->criteria as $criterion) {
            if (!is_array($criterion)
                || !is_string($criterion['id'] ?? null)
                || !preg_match('/^[a-z][a-z0-9_.-]*$/', $criterion['id'])
                || !in_array($criterion['operator'] ?? null, ['at_least', 'at_most', 'equals'], true)
                || !array_key_exists('expected', $criterion)
                || !is_scalar($criterion['expected'])) {
                throw new InvalidArgumentException('Invalid Goal criterion.');
            }
            if (isset($ids[$criterion['id']])) {
                throw new InvalidArgumentException('Duplicate Goal criterion.');
            }
            $ids[$criterion['id']] = true;
        }
        if (count($this->allowedCapabilities) !== count(array_unique($this->allowedCapabilities))) {
            throw new InvalidArgumentException('Duplicate allowed Goal capability.');
        }
        foreach ($this->allowedCapabilities as $capability) {
            if (!is_string($capability) || $capability === '') {
                throw new InvalidArgumentException('Invalid allowed Goal capability.');
            }
        }
    }
}
