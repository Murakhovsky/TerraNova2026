<?php
declare(strict_types=1);

namespace Kernel\Module;

use DomainException;

/**
 * Read-only definition coverage compiler.
 *
 * Domain module.php remains the ONLY identity/contract authority. This report
 * is a reproducible projection, never a runtime binding/permission certificate
 * and never an Action, Workflow or domain write path.
 */
final readonly class CapabilityCatalogCoverageAudit
{
    public function __construct(
        private CanonicalCapabilityCatalog $catalog,
        private ?string $projectRoot = null,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function report(): array
    {
        $root = $this->projectRoot ?? dirname(__DIR__, 3);
        $rows = [];
        $bindings = [];
        $counts = [
            'declared' => 0,
            'typed' => 0,
            'schema_valid' => 0,
            'declarative_only' => 0,
            'invalid_contract' => 0,
            'governance_warnings' => 0,
        ];
        $domains = [];
        foreach ($this->catalog->declared() as $id => $owner) {
            ++$counts['declared'];
            $domains[$owner] = ($domains[$owner] ?? 0) + 1;
            $contract = $this->catalog->describe($id);
            $row = [
                'id' => $id,
                'owner_domain' => $owner,
                'definition_status' => 'DECLARATIVE_ONLY',
                'typed' => $contract !== null,
                'issues' => [],
                'runtime_binding_verified' => false,
                'worker_fleet_certified' => false,
                'tenant_authorized' => null,
                'business_evidence_verified' => false,
            ];
            if ($contract === null) {
                ++$counts['declarative_only'];
                $row['issues'][] = ['code' => 'MISSING_TYPED_CONTRACT', 'severity' => 'migration'];
                $rows[] = $row;
                continue;
            }
            ++$counts['typed'];
            $row['version'] = $contract->version;
            $row['kind'] = $contract->kind;
            $row['lifecycle'] = $contract->lifecycle;
            $row['side_effect_level'] = $contract->sideEffectLevel;
            $row['approval_policy'] = $contract->approvalPolicy;
            $row['binding_kind'] = explode(':', $contract->executionBinding, 2)[0];
            // The Contract must describe the EXISTING execution route, not
            // merely name a plausible handler string.
            $compatible = [
                'query' => ['query', 'read'],
                'command' => ['command', 'action'],
                'job' => ['job'],
                'workflow' => ['workflow'],
                'agent_tool' => ['agent', 'tool'],
            ];
            if (!in_array($row['binding_kind'], $compatible[$contract->kind] ?? [], true)) {
                $row['issues'][] = [
                    'code' => 'BINDING_KIND_MISMATCH', 'severity' => 'error',
                ];
            }
            $row['dependencies'] = $contract->dependencies;
            $row['documentation'] = $contract->documentation;
            $row['tests'] = $contract->tests;
            try {
                $pin = $this->catalog->snapshot($id, $root);
                $row['definition_status'] = 'SCHEMA_VALID_BINDING_UNVERIFIED';
                $row['contract_digest'] = $pin['contract_digest'];
                $row['input_schema_digest'] = $pin['input_schema_digest'];
                $row['output_schema_digest'] = $pin['output_schema_digest'];
                ++$counts['schema_valid'];
            } catch (DomainException $error) {
                $row['definition_status'] = 'CONTRACT_INVALID';
                $row['issues'][] = [
                    'code' => 'INVALID_SCHEMA',
                    'severity' => 'error',
                    'detail' => $error->getMessage(),
                ];
            }
            if (isset($bindings[$contract->executionBinding])) {
                $row['issues'][] = [
                    'code' => 'DUPLICATE_EXECUTION_BINDING',
                    'severity' => 'error',
                    'related_capability' => $bindings[$contract->executionBinding],
                ];
            } else {
                $bindings[$contract->executionBinding] = $id;
            }
            if ($this->hasDependencyCycle($id, [], [])) {
                $row['issues'][] = [
                    'code' => 'CYCLIC_CAPABILITY_DEPENDENCY',
                    'severity' => 'error',
                ];
            }
            foreach ($contract->dependencies as $dependency) {
                if ($dependency === $id || $this->catalog->ownerOf($dependency) === null) {
                    $row['issues'][] = [
                        'code' => 'UNKNOWN_OR_SELF_DEPENDENCY',
                        'severity' => 'error',
                        'related_capability' => $dependency,
                    ];
                } elseif ($this->catalog->describe($dependency) === null) {
                    $row['issues'][] = [
                        'code' => 'DEPENDENCY_NOT_TYPED',
                        'severity' => 'migration',
                        'related_capability' => $dependency,
                    ];
                }
            }
            $paths = array_merge($contract->tests, $contract->documentation === '' ? [] : [$contract->documentation]);
            if ($contract->documentation === '' || $contract->tests === []) {
                $row['issues'][] = ['code' => 'DOCUMENTATION_OR_TESTS_NOT_DECLARED', 'severity' => 'warning'];
            }
            foreach ($paths as $relative) {
                // A contract may refer only to tracked project-relative proof;
                // paths outside the checkout cannot become certification.
                if (!self::trustedExistingFile($root, $relative)) {
                    $row['issues'][] = [
                        'code' => 'MISSING_OR_UNTRUSTED_PROOF_FILE',
                        'severity' => 'warning',
                    ];
                }
            }
            $rows[] = $row;
        }
        // Collisions invalidate EVERY claimant, including the first one.
        $claimants = [];
        foreach ($rows as $row) {
            if (!$row['typed']) continue;
            $contract = $this->catalog->describe($row['id']);
            if ($contract !== null) $claimants[$contract->executionBinding][] = $row['id'];
        }
        foreach ($rows as &$row) {
            if (!$row['typed']) continue;
            $contract = $this->catalog->describe($row['id']);
            if ($contract !== null && count($claimants[$contract->executionBinding] ?? []) > 1) {
                // Replace any earlier duplicate issue with the full sorted peer set.
                $row['issues'] = array_values(array_filter($row['issues'],
                    static fn(array $i):bool => $i['code'] !== 'DUPLICATE_EXECUTION_BINDING'
                ));
                $peers = array_values(array_filter($claimants[$contract->executionBinding],
                    static fn(string $candidate):bool => $candidate !== $row['id']
                ));
                sort($peers, SORT_STRING);
                foreach ($peers as $peer) {
                    $row['issues'][] = [
                        'code' => 'DUPLICATE_EXECUTION_BINDING',
                        'severity' => 'error',
                        'related_capability' => $peer,
                    ];
                }
            }
            if (array_filter($row['issues'], static fn(array $i):bool => $i['severity'] === 'error')) {
                $row['definition_status'] = 'CONTRACT_INVALID';
            }
        }
        unset($row);
        $counts['invalid_contract'] = count(array_filter($rows,
            static fn(array $r):bool => $r['definition_status'] === 'CONTRACT_INVALID'
        ));
        $counts['schema_valid'] = count(array_filter($rows,
            static fn(array $r):bool => $r['typed']
                && isset($r['contract_digest']) && $r['definition_status'] !== 'CONTRACT_INVALID'
        ));
        $counts['governance_warnings'] = array_sum(array_map(
            static fn(array $r):int => count(array_filter($r['issues'],
                static fn(array $i):bool => $i['severity'] === 'warning'
            )), $rows
        ));
        ksort($domains, SORT_STRING);
        usort($rows, static fn(array $left,array $right):int => strcmp($left['id'],$right['id']));
        return [
            'schema_version' => 'cos-capability-coverage-v1',
            'authority' => 'Domain module.php -> CanonicalCapabilityCatalog',
            'scope' => 'STATIC_DEFINITION_ONLY',
            'completeness' => $counts['declarative_only'] === 0 && $counts['invalid_contract'] === 0
                ? 'TYPED_DEFINITIONS_COMPLETE_NOT_CERTIFIED'
                : 'MIGRATION_REQUIRED',
            'certified' => false,
            'runtime_bindings_checked' => false,
            'tenant_permissions_checked' => false,
            'worker_fleet_checked' => false,
            'native_business_outcomes_checked' => false,
            'counts' => $counts,
            'domains' => $domains,
            'capabilities' => $rows,
        ];
    }

    /**
     * Detect dependency loops via catalog-owned typed references.
     * No external execution or dynamic graph evaluation occurs.
     * @param array<string,bool> $visiting
     * @param array<string,bool> $visited
     */
    private function hasDependencyCycle(string $id, array $visiting, array $visited): bool
    {
        if (isset($visiting[$id])) return true;
        if (isset($visited[$id])) return false;
        $contract = $this->catalog->describe($id);
        if ($contract === null) return false;
        $visiting[$id] = true;
        foreach ($contract->dependencies as $dependency) {
            if ($this->catalog->describe($dependency) !== null
                && $this->hasDependencyCycle($dependency, $visiting, $visited)) {
                return true;
            }
        }
        return false;
    }

    private static function trustedExistingFile(string $root, string $relative): bool
    {
        if ($relative === '' || str_contains($relative, '..')
            || !preg_match('#^(?:docs|tests)/[a-zA-Z0-9_./-]+\.(?:md|php|json)$#D', $relative)) {
            return false;
        }
        $root = realpath($root);
        $real = $root !== false ? realpath($root.'/'.$relative) : false;
        return is_string($root) && is_string($real)
            && str_starts_with($real, $root.DIRECTORY_SEPARATOR) && is_file($real);
    }
}
