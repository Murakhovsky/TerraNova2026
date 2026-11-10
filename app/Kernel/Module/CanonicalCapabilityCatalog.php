<?php
declare(strict_types=1);

namespace Kernel\Module;

use InvalidArgumentException;

/**
 * Canonical metadata projection of module manifests. Does not dispatch execution.
 * Legacy descriptive contributions remain visible but cannot be auto-executed.
 */
final class CanonicalCapabilityCatalog
{
    /** @var array<string, CapabilityContract> */
    private array $contracts = [];

    /** @var array<string, string> */
    private array $owners = [];

    /** @var array<string,string> Explicit non-executable legacy classifications. */
    private array $legacyProfiles = [];

    public function __construct(ModuleCatalog $catalog)
    {
        $registry = new ModuleCapabilityRegistry($catalog);
        $this->owners = $registry->all();

        foreach ($catalog->definitions() as $definition) {
            foreach ($definition->contributions->capabilityProfiles as $id => $profile) {
                $this->legacyProfiles[$id] = $profile;
            }
            foreach ($definition->contributions->capabilityContracts as $contract) {
                if ($contract->ownerDomain !== $definition->manifest->id) {
                    throw new InvalidArgumentException(sprintf('Capability %s owner %s differs from manifest %s.', $contract->id, $contract->ownerDomain, $definition->manifest->id));
                }
                if (($this->owners[$contract->id] ?? null) !== $definition->manifest->id) {
                    throw new InvalidArgumentException('Executable capability must have a matching identity in module.php: ' . $contract->id);
                }
                if (isset($this->contracts[$contract->id])) {
                    throw new InvalidArgumentException('Duplicate executable capability: ' . $contract->id);
                }
                $this->contracts[$contract->id] = $contract;
            }
        }
        ksort($this->contracts);
        ksort($this->owners);
        ksort($this->legacyProfiles);
    }

    public function describe(string $id): ?CapabilityContract
    {
        return $this->contracts[$id] ?? null;
    }

    /**
     * Deterministic current-build snapshot. No claim of runtime handler
     * availability: module manifests and schema bytes alone are not evidence.
     * @return array{capability_id:string,version:string,contract_digest:string,input_schema_digest:string,output_schema_digest:string}
     */
    public function snapshot(string $id, ?string $projectRoot = null): array
    {
        $contract = $this->describe($id);
        if ($contract === null) {
            throw new \DomainException('Missing typed capability contract: ' . $id);
        }
        return CapabilityContractFingerprint::snapshot($contract, $projectRoot);
    }

    /**
     * A pinned Plan MUST NOT silently drift to a new handler/schema.
     * Historical version execution needs a separately verified immutable
     * snapshot and compatible adapter, never auto-selected by this catalog.
     */
    public function resolvePinned(string $id, string $version, string $digest, ?string $projectRoot = null): CapabilityContract
    {
        $contract = $this->describe($id);
        if ($contract === null || $contract->version !== $version
            || !preg_match('/^[a-f0-9]{64}$/D', $digest)
            || !hash_equals($this->snapshot($id, $projectRoot)['contract_digest'], $digest)) {
            throw new \DomainException('Pinned capability version or digest unavailable; re-approval required.');
        }
        return $contract;
    }

    /** Explicit semantic classification: never an invocation grant. */
    public function legacyProfileOf(string $id): ?string
    {
        return $this->legacyProfiles[$id] ?? null;
    }

    public function ownerOf(string $id): ?string
    {
        return $this->owners[$id] ?? null;
    }

    /** @return array<string,CapabilityContract> */
    public function executables(): array
    {
        return $this->contracts;
    }

    /** @return array<string,string> */
    public function declared(): array
    {
        return $this->owners;
    }

    /**
     * Status is about declaration/binding reconciliation, NOT runtime authorization.
     * $registeredBindings is an evidence map from the existing DI/handler/tool runtime:
     * binding id => canonical capability id.
     *
     * @param array<string,string> $registeredBindings
     * @return array<string,array{owner:string,status:string,binding:?string}>
     */
    public function driftReport(array $registeredBindings = []): array
    {
        $report = [];
        foreach ($this->owners as $id => $owner) {
            $contract = $this->contracts[$id] ?? null;
            $status = $contract === null ? 'DECLARATIVE_ONLY' : 'INVALID';
            if ($contract !== null) {
                $status = $contract->lifecycle === 'deprecated' || $contract->lifecycle === 'retired'
                    ? 'DEPRECATED'
                    : (($registeredBindings[$contract->executionBinding] ?? null) === $id ? 'CONSISTENT' : 'INVALID');
            }
            $report[$id] = ['owner' => $owner, 'status' => $status, 'binding' => $contract?->executionBinding];
        }
        foreach ($registeredBindings as $binding => $id) {
            if (!isset($this->owners[$id])) {
                $report[$id] = ['owner' => '', 'status' => 'EXECUTABLE_ONLY', 'binding' => $binding];
            }
        }
        ksort($report);
        return $report;
    }
}
